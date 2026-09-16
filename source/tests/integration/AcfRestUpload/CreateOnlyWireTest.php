<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use AcfService\AcfService;
use ApiSponsorManager\Test\NativeTestCase;
use ApiSponsorManager\Test\PhpMultipartParser;
use ModularityFrontendForm\Config\Config;
use ModularityFrontendForm\Config\ModuleConfigInterface;
use ModularityFrontendForm\DataProcessor\Handlers\WebHookHandler;
use ModularityFrontendForm\DataProcessor\Handlers\Webhook\UploadedFileSnapshots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use WpService\Implementations\NativeWpService;
use WP_REST_Request;

#[Group('wire')]
class CreateOnlyWireTest extends NativeTestCase
{
    private array $files = [];
    private array $attachments = [];

    public function set_up(): void
    {
        parent::set_up();
        $sender = getenv('SENDER_PLUGIN_DIR');
        if (!$sender) {
            self::markTestSkipped('SENDER_PLUGIN_DIR must identify the sender worktree with dependencies.');
        }
        self::assertFileExists($sender . '/vendor/autoload.php');
        $loader = require $sender . '/vendor/autoload.php';
        // Keep the receiver's PHPUnit and service versions first.
        $loader->unregister();
        $loader->register(false);
        // The real plugin bootstrap selects and boots the embedded provider.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_action('add_attachment', function (int $id): void { $this->attachments[] = $id; });
        add_action('doing_it_wrong_run', function (string $function, string $message): void {
            if ($function === 'rest_handle_multi_type_schema') {
                self::assertStringContainsString('acf[contact_method]', $message);
                $this->setExpectedIncorrectUsage($function);
            }
        }, 10, 2);
        $GLOBALS['wp_rest_server'] = null;
    }

    public function tear_down(): void
    {
        foreach ($this->attachments as $id) {
            wp_delete_attachment($id, true);
        }
        foreach ($this->files as $path) {
            if (is_file($path)) { unlink($path); }
        }
        parent::tear_down();
    }

    #[DataProvider('destinations')]
    public function testSenderBytesReachNativeSavingOrAnExplicitVersionRejection(string $route, string $type, string $fixture, bool $reject): void
    {
        $bytes = file_get_contents(DIR_TESTDATA . '/images/' . $fixture);
        self::assertIsString($bytes);
        $original = wp_tempnam('sender-selected.png');
        $this->files[] = $original;
        file_put_contents($original, $bytes);
        $acf = $this->createMock(AcfService::class);
        $acf->method('getFieldObject')->willReturn(['key' => 'field_selected', 'name' => 'selected', 'type' => 'image']);
        $snapshots = new UploadedFileSnapshots([
            'name' => ['field_selected' => ['selected.png']],
            'type' => ['field_selected' => ['image/png']],
            'tmp_name' => ['field_selected' => [$original]],
            'error' => ['field_selected' => [UPLOAD_ERR_OK]],
            'size' => ['field_selected' => [strlen($bytes)]],
        ], $acf);
        $snapshotPaths = array_column($snapshots->getFileMap(), 'tmp_name');
        $payload = ['title' => 'Create-only sender wire', 'status' => 'draft', 'acf' => [
            'image' => '{{selected.0}}', 'date' => '20260915', 'time' => '12:00:00',
            'due_date' => '20260930', 'due_time' => '12:00:00', 'description' => 'Selected sender bytes',
            'contact_method' => ['mail'], 'organization_name' => 'Isolated organization',
            'organization_contact' => 'Test contact', 'organization_email' => 'contact@example.test',
            'organization_phone' => 123456, 'organization_number' => '123456-7890',
        ]];
        $url = 'https://sender-wire.example.test/wp-json/wp/v2/' . $route;
        $config = $this->createMock(Config::class);
        $config->method('getFieldNamespace')->willReturn('acf');
        $module = $this->createMock(ModuleConfigInterface::class);
        $module->method('getWebHookHandlerConfig')->willReturn((object) [
            'requestFormat' => 'multipart-create', 'callbackUrl' => $url,
            'body' => wp_json_encode($payload),
        ]);
        $attempts = 0;
        $response = null;
        $transport = function ($pre, array $args, string $actualUrl) use ($url, $route, $bytes, $payload, $reject, &$attempts, &$response) {
            if ($actualUrl !== $url) { return $pre; }
            $attempts++;
            self::assertSame('2', $args['headers']['X-ACF-Rest-Upload-Version']);
            self::assertArrayNotHasKey('idempotency-key', array_change_key_case($args['headers']));
            $parsed = PhpMultipartParser::parse(['body' => $args['body'], 'contentType' => $args['headers']['Content-Type']]);
            $reference = $parsed['params']['acf']['image'];
            self::assertMatchesRegularExpression('/^\$file:[A-Za-z0-9_-]+$/', $reference);
            $key = substr($reference, strlen('$file:'));
            $file = &$parsed['files']['_acf_rest_files'];
            self::assertCount(1, $file['name']);
            self::assertTrue($file['uploaded'][$key]);
            self::assertSame($bytes, base64_decode($file['content'][$key], true));
            self::assertSame($payload['title'], $parsed['params']['title']);
            self::assertSame('Selected sender bytes', $parsed['params']['acf']['description']);
            $path = wp_tempnam('parsed-selected.png');
            $this->files[] = $path;
            file_put_contents($path, base64_decode($file['content'][$key], true));
            unset($file['content'], $file['uploaded']);
            $file['tmp_name'][$key] = $path;
            $request = new WP_REST_Request('POST', '/wp/v2/' . $route);
            foreach ($args['headers'] as $name => $value) { $request->set_header($name, $value); }
            if ($reject) {
                // Deliberately simulate an incompatible protocol at the receiver boundary.
                $request->set_header('X-ACF-Rest-Upload-Version', '3');
            }
            $request->set_body_params($parsed['params']);
            $request->set_file_params($parsed['files']);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
            acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
            $response = rest_get_server()->dispatch($request);
            return ['response' => ['code' => $response->get_status()], 'headers' => [], 'body' => wp_json_encode($response->get_data())];
        };
        add_filter('pre_http_request', $transport, 10, 3);
        try {
            $result = (new WebHookHandler(new NativeWpService(), $acf, $config, $module, (object) [], uploadedFileSnapshots: $snapshots))
                ->handle(['acf' => ['field_selected' => ['']]], new WP_REST_Request());
        } finally {
            remove_filter('pre_http_request', $transport, 10);
        }
        self::assertSame(1, $attempts);
        self::assertSame(!$reject, $result?->isOk());
        foreach ($snapshotPaths as $path) { self::assertFileDoesNotExist($path); }
        self::assertSame($reject ? 400 : 201, $response?->get_status(), wp_json_encode($response?->get_data()));
        if ($reject) {
            self::assertNotEmpty($result?->getErrors());
            self::assertSame(['status' => 400], $result->getErrors()[0]->get_error_data());
            self::assertStringContainsString('400', $result->getErrors()[0]->get_error_message());
            self::assertSame([], $this->attachments);
            self::assertSame(0, (int) wp_count_posts($type)->draft);
            fwrite(STDERR, "Native wire: incompatible version HTTP 400, visible sender error, no posts or images, attempts=1\n");
            return;
        }
        $id = (int) $response->get_data()['id'];
        self::assertSame($type, get_post_type($id));
        self::assertSame('Create-only sender wire', get_post($id)->post_title);
        $image = (int) get_field('image', $id, false);
        self::assertSame([$image], $this->attachments);
        self::assertSame('attachment', get_post_type($image));
        self::assertSame($id, (int) get_post($image)->post_parent);
        $field = acf_get_field(get_post_meta($id, '_image', true));
        self::assertSame($type === 'offering' ? 'group_69a99cbe03b51' : 'group_69a97690d547c', $field['parent']);
        self::assertArrayHasKey('acf', $response->get_data());
        $file = get_attached_file($image);
        self::assertIsArray(getimagesize($file));
        self::assertFileIsReadable($file);
        self::assertSame($bytes, file_get_contents($file));
        fwrite(STDERR, sprintf("Native wire: %s HTTP 201 post=%d image=%d parent=%d sha256=%s attempts=%d\n", $type, $id, $image, (int) get_post($image)->post_parent, hash_file('sha256', $file), $attempts));
    }

    public static function destinations(): array
    {
        return [
            'offering' => ['sponsor-offerings', 'offering', 'one-blue-pixel-100x100.png', false],
            'assignment' => ['sponsor-assignments', 'assignment', 'test-image.png', false],
            'incompatible version' => ['sponsor-offerings', 'offering', 'test-image.png', true],
        ];
    }
}
