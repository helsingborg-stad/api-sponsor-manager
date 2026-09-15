<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use AcfService\Implementations\NativeAcfService;
use ApiSponsorManager\AcfRestUpload\Receiver;
use ApiSponsorManager\Helper\NotificationServices\WordPressNotificationService;
use ApiSponsorManager\Notifications;
use ApiSponsorManager\Test\NativeTestCase;
use ApiSponsorManager\Test\PhpMultipartParser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpService\Implementations\NativeWpService;

/**
 * Native sponsor controllers and production ACF groups. No replacement routes.
 * ACF's select schema uses "int" instead of JSON Schema's "integer".
 * The fixture checks the exact affected field rather than suppressing notices.
 * @expectedIncorrectUsage rest_handle_multi_type_schema
 */
class ReceiverTest extends NativeTestCase
{
    private Receiver $receiver;
    private array $mail = [];
    private array $completions = [];
    private array $tempFiles = [];
    private array $createdAttachments = [];

    public function set_up(): void
    {
        parent::set_up();
        add_action('doing_it_wrong_run', static function (string $function, string $message): void {
            if ($function === 'rest_handle_multi_type_schema') {
                self::assertStringContainsString('acf[contact_method]', $message);
            }
        }, 10, 2);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_action('add_attachment', function (int $id): void { $this->createdAttachments[] = $id; });
        // Replace only service instances so interrupted test contexts cannot leak.
        // The plugin's registered post types, ACF groups, and native routes remain.
        global $wp_filter, $wp_rest_server;
        foreach ($wp_filter as $hook => $registry) {
            foreach ($registry->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback['function'];
                    if (is_array($function) && ($function[0] instanceof Receiver || $function[0] instanceof Notifications)) {
                        remove_filter($hook, $function, $priority);
                    }
                }
            }
        }
        $this->receiver = new Receiver();
        $this->receiver->addHooks();
        $wp = new NativeWpService();
        (new Notifications($wp, new NativeAcfService(), new WordPressNotificationService($wp)))->addHooks();
        // Extra protocol shapes supplement, but never replace, production image fields.
        acf_add_local_field_group([
            'key' => 'group_upload_shapes', 'title' => 'Protocol shapes', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'offering']]],
            'fields' => [
                ['key' => 'field_shapes_optional', 'name' => 'optional_image', 'type' => 'image', 'allow_multipart_rest_upload' => 1],
                ['key' => 'field_shapes_gallery', 'name' => 'gallery', 'type' => 'gallery', 'allow_multipart_rest_upload' => 1],
                ['key' => 'field_shapes_empty_gallery', 'name' => 'empty_gallery', 'type' => 'gallery', 'allow_multipart_rest_upload' => 1],
                ['key' => 'field_shapes_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
                    ['key' => 'field_shapes_caption', 'name' => 'caption', 'type' => 'text'],
                    ['key' => 'field_shapes_marker', 'name' => 'marker', 'type' => 'text'],
                ]],
            ],
        ]);
        $templates = [];
        foreach (['offering', 'assignment'] as $type) {
            foreach (['submit', 'publish'] as $trigger) {
                $templates[] = ['trigger' => $trigger, 'post_type' => $type, 'recipients' => 'recipient+{ID}@example.test', 'subject' => $trigger . ' {ID}', 'message' => 'Saved {ID}'];
            }
        }
        update_field('field_69bbaf272549d', $templates, 'options');
        add_filter('pre_wp_mail', function ($return, array $attributes) {
            $this->mail[] = $attributes;
            return true;
        }, 10, 2);
        add_action(Receiver::ACTION_AFTER_INSERT, function (int $id): void { $this->completions[] = $id; });
        $wp_rest_server = null;
    }

    public function tear_down(): void
    {
        foreach ($this->createdAttachments as $id) {
            if (get_post_type($id) === 'attachment') { wp_delete_attachment($id, true); }
        }
        acf_remove_local_field_group('group_upload_shapes');
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) { unlink($file); }
        }
        parent::tear_down();
    }

    private function acf(): array
    {
        return ['image' => '$file:hero', 'date' => '20260915', 'time' => '12:00:00', 'due_date' => '20260930', 'due_time' => '12:00:00', 'description' => 'Original description', 'contact_method' => ['mail'],
            'organization_name' => 'Isolated organization', 'organization_contact' => 'Test contact', 'organization_email' => 'contact@example.test', 'organization_phone' => 123456, 'organization_number' => '123456-7890'];
    }

    private function request(string $route = 'sponsor-offerings', ?string $key = null): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/' . $route);
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', $key ?? wp_generate_uuid4());
        $request->set_body_params(['title' => 'Original title', 'status' => 'draft', 'acf' => $this->acf()]);
        $path = wp_tempnam('sponsor-integration.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $this->tempFiles[] = $path;
        $request->set_file_params(['_acf_rest_files' => ['hero' => ['name' => 'sponsor.jpg', 'type' => 'image/jpeg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]]]);
        return $request;
    }

    private function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        $_SERVER['REQUEST_METHOD'] = $request->get_method();
        $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        // ACF normally reads HTTP routing globals before core builds route args.
        // Supply that context explicitly in the internal-dispatch test harness.
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        return rest_get_server()->dispatch($request);
    }

    private function ids(string $type): array
    {
        return get_posts(['post_type' => $type, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]);
    }

    private function option(WP_REST_Request $request): string
    {
        return 'acf_rest_upload_' . hash('sha256', $request->get_route() . '|POST|' . get_current_user_id() . '|' . strtolower($request->get_header('Idempotency-Key')));
    }

    public function testBothProductionRoutesPersistNativePostsAndRawImages(): void
    {
        foreach (['sponsor-offerings' => 'offering', 'sponsor-assignments' => 'assignment'] as $route => $type) {
            $this->completions = $this->mail = [];
            self::assertTrue(post_type_exists($type));
            $request = $this->request($route);
            $response = $this->dispatch($request);
            self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
            $id = (int) $response->get_data()['id'];
            self::assertSame($type, get_post_type($id));
            self::assertSame('Original title', get_post($id)->post_title);
            $image = (int) get_field('image', $id, false);
            self::assertGreaterThan(0, $image);
            self::assertSame('attachment', get_post_type($image));
            self::assertSame($id, (int) get_post($image)->post_parent);
            $field = acf_get_field(get_post_meta($id, '_image', true));
            self::assertSame($type === 'offering' ? 'group_69a99cbe03b51' : 'group_69a97690d547c', $field['parent']);
            self::assertSame([$id], $this->completions);
            self::assertCount(1, $this->mail);
            self::assertSame(['recipient+' . $id . '@example.test'], $this->mail[0]['to']);
            self::assertSame('complete', get_option($this->option($request))['phase']);
        }
    }

    public function testReplayDoesNotCreatePostsAttachmentsOrNotifications(): void
    {
        $key = wp_generate_uuid4();
        $first = $this->dispatch($this->request(key: $key));
        self::assertSame(201, $first->get_status(), wp_json_encode($first->get_data()));
        $posts = $this->ids('offering');
        $attachments = $this->ids('attachment');
        $second = $this->dispatch($this->request(key: $key));
        self::assertSame(201, $second->get_status());
        self::assertSame(['id' => $first->get_data()['id']], $second->get_data());
        self::assertSame($posts, $this->ids('offering'));
        self::assertSame($attachments, $this->ids('attachment'));
        self::assertCount(1, $this->mail);
        self::assertCount(1, $this->completions);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatibleKeys')]
    public function testChangedPayloadCannotReuseACompletedCreateKey(string $change, bool $pruned): void
    {
        $key = wp_generate_uuid4();
        $original = $this->request(key: $key);
        $first = $this->dispatch($original);
        self::assertSame(201, $first->get_status());
        if ($pruned) { delete_option($this->option($original)); }
        $posts = $this->ids('offering');
        $attachments = $this->ids('attachment');
        $retry = $this->request(key: $key);
        if ($change === 'title') {
            $retry->set_param('title', 'Different operation');
        } else {
            $files = $retry->get_file_params();
            file_put_contents($files['_acf_rest_files']['hero']['tmp_name'], 'changed', FILE_APPEND);
            $files['_acf_rest_files']['hero']['size'] += 7;
            $retry->set_file_params($files);
        }
        $response = $this->dispatch($retry);
        self::assertSame(409, $response->get_status());
        self::assertSame('acf_rest_upload_key_conflict', $response->get_data()['code']);
        self::assertSame($posts, $this->ids('offering'));
        self::assertSame($attachments, $this->ids('attachment'));
        self::assertCount(1, $this->mail);
    }

    public static function incompatibleKeys(): array
    {
        return [['title', false], ['bytes', false], ['title', true], ['bytes', true]];
    }

    public function testChangedUpdateCannotReuseItsCompletedKey(): void
    {
        $first = $this->dispatch($this->request());
        self::assertSame(201, $first->get_status());
        $id = (int) $first->get_data()['id'];
        $key = wp_generate_uuid4();
        self::assertSame(200, $this->dispatch($this->request('sponsor-offerings/' . $id, $key))->get_status());
        $retry = $this->request('sponsor-offerings/' . $id, $key);
        $retry->set_param('title', 'Conflicting update');
        self::assertSame(409, $this->dispatch($retry)->get_status());
        self::assertSame('Original title', get_post($id)->post_title);
    }

    public function testDeletedResourceDoesNotReplayAsSuccess(): void
    {
        $key = wp_generate_uuid4();
        $first = $this->dispatch($this->request(key: $key));
        self::assertSame(201, $first->get_status());
        wp_delete_post((int) $first->get_data()['id'], true);
        $response = $this->dispatch($this->request(key: $key));
        self::assertSame(410, $response->get_status());
        self::assertSame('acf_rest_upload_resource_gone', $response->get_data()['code']);
        self::assertSame([], $this->ids('offering'));
        self::assertCount(1, $this->mail);
    }

    public function testNativePermissionDenialCannotCreateMediaOrClaim(): void
    {
        $user = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $user->add_cap('upload_files');
        wp_set_current_user($user->ID);
        $before = $this->ids('attachment');
        $request = $this->request();
        self::assertSame(403, $this->dispatch($request)->get_status());
        self::assertFalse(get_option($this->option($request)));
        self::assertSame($before, $this->ids('attachment'));
        self::assertSame([], $this->mail);
    }

    public function testActiveClaimReturnsRetryAfterWithoutChangingItsOwner(): void
    {
        $request = $this->request();
        $option = $this->option($request);
        add_option($option, ['status' => 'active', 'owner' => 'another-request', 'expires_at' => time() + 600, 'saved_post_id' => null], '', false);
        $response = $this->dispatch($request);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_in_progress', $response->get_data()['code']);
        self::assertNotEmpty($response->get_headers()['Retry-After']);
        self::assertSame('another-request', get_option($option)['owner']);
        self::assertSame([], $this->mail);
    }

    public function testMissingRequiredImageRetainsNativeValidationWithAndWithoutProtocol(): void
    {
        $acf = array_replace($this->acf(), ['image' => null]);
        $protocol = $this->request();
        $protocol->set_body_params(['acf' => $acf]);
        $protocol->set_file_params([]);
        $json = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $json->set_body_params(['acf' => $acf]);
        foreach ([$protocol, $json] as $request) {
            self::assertSame(400, $this->dispatch($request)->get_status());
        }
        self::assertSame([], $this->ids('offering'));
        self::assertSame([], $this->mail);
    }

    public function testUnrelatedNativeValidationErrorIsNotCleared(): void
    {
        $request = $this->request();
        $request->set_body_params(['status' => 'not-a-registered-status', 'acf' => $this->acf()]);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertArrayHasKey('status', $response->get_data()['data']['params']);
        self::assertFalse(get_option($this->option($request)));
        self::assertSame([], $this->mail);
    }

    public function testLateFailureRemovesNewPostAndMediaWithoutMail(): void
    {
        $beforePosts = $this->ids('offering');
        $beforeMedia = $this->ids('attachment');
        $request = $this->request();
        add_filter('rest_request_after_callbacks', static fn ($response, $handler, $actual) => $actual === $request ? new WP_Error('late_failure', 'Late failure.', ['status' => 500]) : $response, 20, 3);
        self::assertSame(500, $this->dispatch($request)->get_status());
        self::assertSame($beforePosts, $this->ids('offering'));
        self::assertSame($beforeMedia, $this->ids('attachment'));
        self::assertSame([], $this->mail);
        self::assertSame([], $this->completions);
    }

    public function testNativeAcfRejectionRemovesTheCreatedAttachment(): void
    {
        $before = $this->ids('offering');
        $created = null;
        add_action('add_attachment', static function (int $id) use (&$created): void { $created = $id; });
        add_filter('acf/validate_rest_value/type=image', static function ($valid, $value) {
            return is_numeric($value) && (int) $value > 0 ? new WP_Error('rest_invalid_param', 'Rejected image.', ['status' => 400]) : $valid;
        }, 20, 2);
        self::assertSame(400, $this->dispatch($this->request())->get_status());
        self::assertNotNull($created, 'The validator must reject a real newly created attachment.');
        self::assertNull(get_post($created));
        self::assertSame($before, $this->ids('offering'));
        self::assertSame([], $this->mail);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fileDeletionOutcomes')]
    public function testAttachmentInsertFailureCleansTheMovedFileOrRetainsRecovery(bool $canDelete): void
    {
        $request = $this->request();
        $moved = null;
        add_filter('wp_handle_upload', static function (array $upload, string $context) use (&$moved): array {
            if ($context === 'sideload') { $moved = $upload['file']; }
            return $upload;
        }, 10, 2);
        add_filter('wp_insert_post_empty_content', static function ($empty, array $post) use (&$moved) {
            if (($post['post_type'] ?? '') === 'attachment') {
                self::assertNotNull($moved);
                self::assertFileExists($moved, 'The native upload moved before attachment insertion failed.');
                return true;
            }
            return $empty;
        }, 10, 2);
        if (!$canDelete) {
            add_filter('wp_delete_file', static function ($file) use (&$moved) { return $file === $moved ? '' : $file; });
        }
        try {
            self::assertGreaterThanOrEqual(400, $this->dispatch($request)->get_status());
            self::assertNotNull($moved);
            if ($canDelete) {
                self::assertFileDoesNotExist($moved);
                self::assertFalse(get_option($this->option($request)));
            } else {
                self::assertFileExists($moved);
                $state = get_option($this->option($request));
                self::assertIsArray($state);
                self::assertSame('recovery_failed', $state['phase']);
                self::assertSame([$moved], $state['moved_files']);
                self::assertSame(425, $this->dispatch($this->request(key: $request->get_header('Idempotency-Key')))->get_status());
            }
            self::assertSame([], $this->ids('attachment'));
            self::assertSame([], $this->ids('offering'));
            self::assertSame([], $this->mail);
        } finally {
            if (is_string($moved) && is_file($moved)) { unlink($moved); }
        }
    }

    public static function fileDeletionOutcomes(): array
    {
        return [[true], [false]];
    }

    public function testFailedOuterSideloadDoesNotDeleteANestedSideload(): void
    {
        $nestedFile = $this->request()->get_file_params()['_acf_rest_files']['hero'];
        $outer = $this->request();
        $outerPath = $nestedId = null;
        $entered = false;
        add_filter('wp_handle_upload', static function (array $upload) use (&$entered, &$outerPath, &$nestedId, $nestedFile): array {
            if (!$entered) {
                $entered = true;
                $outerPath = $upload['file'];
                $nestedId = media_handle_sideload($nestedFile, 0);
                self::assertIsInt($nestedId);
            }
            return $upload;
        });
        add_filter('wp_insert_post_empty_content', static function ($empty, array $post) use (&$outerPath) {
            return ($post['file'] ?? null) === $outerPath ? true : $empty;
        }, 10, 2);
        self::assertGreaterThanOrEqual(400, $this->dispatch($outer)->get_status());
        self::assertNotNull($outerPath);
        self::assertFileDoesNotExist($outerPath);
        self::assertSame('attachment', get_post_type($nestedId));
        self::assertFileExists(get_attached_file($nestedId));
        self::assertSame([], $this->mail);
    }

    public function testLatePublishFailureRestoresTitleStatusAcfAndSharedMedia(): void
    {
        $first = $this->dispatch($this->request());
        self::assertSame(201, $first->get_status(), wp_json_encode($first->get_data()));
        $id = (int) $first->get_data()['id'];
        $image = (int) get_field('image', $id, false);
        $this->mail = $this->completions = [];
        $request = $this->request('sponsor-offerings/' . $id);
        $request->set_body_params(['title' => 'Changed title', 'status' => 'publish', 'acf' => array_replace($this->acf(), ['description' => 'Changed description'])]);
        $newImage = null;
        add_filter('rest_request_after_callbacks', static function ($response, $handler, $actual) use ($request, $id, &$newImage) {
            if ($actual === $request) {
                $newImage = (int) get_field('image', $id, false);
                return new WP_Error('late_failure', 'Late failure.', ['status' => 500]);
            }
            return $response;
        }, 20, 3);
        self::assertSame(500, $this->dispatch($request)->get_status());
        self::assertNotSame($image, $newImage);
        self::assertSame('Original title', get_post($id)->post_title);
        self::assertSame('draft', get_post_status($id));
        self::assertSame('Original description', get_field('description', $id, false));
        self::assertSame($image, (int) get_field('image', $id, false));
        self::assertNotNull(get_post($image));
        self::assertNull(get_post($newImage));
        self::assertSame([], $this->mail);
    }

    public function testMixedGalleryAndExplicitEmptyAndNullValues(): void
    {
        $left = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $right = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = $this->request();
        $request->set_body_params(['title' => 'Gallery', 'acf' => $this->acf() + ['gallery' => [(string) $left, '$file:hero', (string) $right], 'optional_image' => $left], '_acf_rest_nulls' => ['acf[optional_image]']]);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        $id = (int) $response->get_data()['id'];
        $image = (int) get_field('image', $id, false);
        self::assertSame([$left, $image, $right], array_map('intval', get_field('gallery', $id, false)));
        self::assertFalse(metadata_exists('post', $id, 'optional_image'));
        $clear = $this->request('sponsor-offerings/' . $id);
        $clear->set_body_params(['acf' => $this->acf(), '_acf_rest_empty' => ['acf[gallery]']]);
        self::assertSame(200, $this->dispatch($clear)->get_status());
        self::assertSame([], get_post_meta($id, 'gallery', true));
        self::assertNotNull(get_post($left));
        self::assertNotNull(get_post($right));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('interruptionHooks')]
    public function testInterruptedNativeSavingNeverReplaysAsCompleted(string $hook, string $phase): void
    {
        $request = $this->request();
        $interrupt = static function ($post, $actual) use ($request): void {
            if ($actual === $request) { throw new \RuntimeException('Simulated process interruption'); }
        };
        add_action($hook, $interrupt, 20, 2);
        try {
            $this->dispatch($request);
            self::fail('The native hook must interrupt the operation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated process interruption', $exception->getMessage());
        }
        remove_action($hook, $interrupt, 20);
        $option = $this->option($request);
        $state = get_option($option);
        self::assertSame($phase, $state['phase']);
        $state['expires_at'] = time() - 1;
        update_option($option, $state);
        $posts = $this->ids('offering');
        $attachments = $this->ids('attachment');
        $retry = $this->dispatch($this->request(key: $request->get_header('Idempotency-Key')));
        self::assertSame(425, $retry->get_status());
        self::assertSame($posts, $this->ids('offering'));
        self::assertSame($attachments, $this->ids('attachment'));
        self::assertSame([], $this->mail);
        $this->receiver->postDispatch(new WP_Error('interrupted', 'Interrupted.', ['status' => 500]), null, $request);
    }

    public static function interruptionHooks(): array
    {
        return [['rest_insert_offering', 'inserted'], ['rest_after_insert_offering', 'saved']];
    }

    public function testFailedDeletionRetainsRecoveryFailureAcrossRetry(): void
    {
        $request = $this->request();
        add_filter('rest_request_after_callbacks', static fn ($response, $handler, $actual) => $actual === $request ? new WP_Error('late_failure', 'Late failure.', ['status' => 500]) : $response, 20, 3);
        add_filter('pre_delete_post', static fn ($delete, $post) => $post->post_type === 'offering' ? false : $delete, 10, 2);
        self::assertSame(500, $this->dispatch($request)->get_status());
        $option = $this->option($request);
        self::assertSame('recovery_failed', get_option($option)['phase']);
        $posts = $this->ids('offering');
        self::assertCount(1, $posts);
        self::assertSame(425, $this->dispatch($this->request(key: $request->get_header('Idempotency-Key')))->get_status());
        self::assertSame($posts, $this->ids('offering'));
        self::assertSame([], $this->mail);
    }

    public function testNestedNativeCreateSurvivesOuterFailure(): void
    {
        $outer = $this->request();
        $outerId = $nestedId = null;
        add_action('rest_insert_offering', function ($post, $actual) use ($outer, &$outerId, &$nestedId): void {
            if ($actual === $outer) {
                $outerId = (int) $post->ID;
                $response = $this->dispatch($this->request());
                self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
                $nestedId = (int) $response->get_data()['id'];
            }
        }, 20, 2);
        add_filter('rest_request_after_callbacks', static fn ($response, $handler, $actual) => $actual === $outer ? new WP_Error('outer_failure', 'Outer failed.', ['status' => 500]) : $response, 20, 3);
        self::assertSame(500, $this->dispatch($outer)->get_status());
        self::assertNotNull($nestedId);
        self::assertNotSame($outerId, $nestedId);
        self::assertNull(get_post($outerId));
        self::assertSame('offering', get_post_type($nestedId));
        self::assertSame('complete', get_post_meta($nestedId, Receiver::META_CREATE_IDENTITY, true)['status']);
        self::assertSame([$nestedId], $this->completions);
        self::assertCount(1, $this->mail);
    }

    #[\PHPUnit\Framework\Attributes\Group('wire')]
    public function testSenderWireBodyPassesThroughPhpParsingIntoNativeSaving(): void
    {
        $sender = getenv('SENDER_PLUGIN_DIR');
        if (!$sender) { self::markTestSkipped('SENDER_PLUGIN_DIR must identify the sender worktree.'); }
        $encoderFile = $sender . '/source/php/DataProcessor/Handlers/Webhook/MultipartFormDataEncoder.php';
        self::assertFileExists($encoderFile);
        require_once $encoderFile;
        $left = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $right = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $payload = ['title' => 'Wire fixture', 'acf' => array_replace($this->acf(), [
            'image' => '$file:0', 'gallery' => [$left, '$file:0', $right], 'optional_image' => null, 'empty_gallery' => [],
            'rows' => [['caption' => null, 'marker' => 'first'], ['caption' => 'Retained', 'marker' => 'second']],
        ])];
        $encoded = (new \ModularityFrontendForm\DataProcessor\Handlers\Webhook\MultipartFormDataEncoder())->encode($payload, [0 => [
            'name' => 'wire.jpg', 'type' => 'image/jpeg', 'tmp_name' => DIR_TESTDATA . '/images/canola.jpg',
        ]]);
        $parsed = PhpMultipartParser::parse($encoded);
        self::assertSame([(string) $left, '$file:0', (string) $right], $parsed['params']['acf']['gallery']);
        self::assertContains('acf[rows][0][caption]', $parsed['params']['_acf_rest_nulls']);
        self::assertContains('acf[empty_gallery]', $parsed['params']['_acf_rest_empty']);
        self::assertTrue($parsed['files']['_acf_rest_files']['uploaded'][0]);
        self::assertCount(1, $parsed['files']['_acf_rest_files']['name']);
        $path = wp_tempnam('wire.jpg');
        $this->tempFiles[] = $path;
        $bytes = base64_decode($parsed['files']['_acf_rest_files']['content'][0], true);
        self::assertSame(file_get_contents(DIR_TESTDATA . '/images/canola.jpg'), $bytes);
        file_put_contents($path, $bytes);
        $files = $parsed['files'];
        unset($files['_acf_rest_files']['content'], $files['_acf_rest_files']['uploaded']);
        $files['_acf_rest_files']['tmp_name'][0] = $path;
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', wp_generate_uuid4());
        $request->set_body_params($parsed['params']);
        $request->set_file_params($files);
        add_action('rest_insert_offering', static function ($post, $actual) use ($request): void {
            if ($actual === $request) {
                self::assertNull($actual['acf']['rows'][0]['caption']);
                self::assertSame('Retained', $actual['acf']['rows'][1]['caption']);
                self::assertSame([], $actual['acf']['empty_gallery']);
            }
        }, 20, 2);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        $id = (int) $response->get_data()['id'];
        $image = (int) get_field('image', $id, false);
        self::assertSame([$left, $image, $right], array_map('intval', get_field('gallery', $id, false)));
        self::assertSame($id, (int) get_post($image)->post_parent);
        self::assertSame([], get_post_meta($id, 'empty_gallery', true));
        self::assertSame('Retained', get_post_meta($id, 'rows_1_caption', true));
        self::assertFalse(metadata_exists('post', $id, 'rows_0_caption'));
    }

    public function testNumericFileKeysAndMalformedParts(): void
    {
        $numeric = $this->request();
        $numeric->set_body_params(['acf' => array_replace($this->acf(), ['image' => '$file:0'])]);
        $numeric->set_file_params(['_acf_rest_files' => [0 => $numeric->get_file_params()['_acf_rest_files']['hero']]]);
        $response = $this->dispatch($numeric);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        foreach (['missing', 'unexpected', 'conflict'] as $case) {
            $request = $this->request();
            if ($case === 'missing') { $request->set_file_params([]); }
            if ($case === 'unexpected') { $request->set_body_params(['acf' => array_replace($this->acf(), ['image' => 123])]); }
            if ($case === 'conflict') { $request->set_body_params(['acf' => $this->acf(), '_acf_rest_nulls' => ['acf[image]']]); }
            self::assertSame(400, $this->dispatch($request)->get_status(), $case);
        }
    }
}
