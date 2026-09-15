<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use ApiSponsorManager\Test\PhpMultipartParser;
use PHPUnit\Framework\Attributes\DataProvider;
use AcfService\Implementations\NativeAcfService;
use ApiSponsorManager\AcfRestUpload\CreateReceiver;
use ApiSponsorManager\SponsorUploads;
use WpService\Implementations\NativeWpService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Production sponsor routes and field groups, with real image bytes.
 */
class CreateReceiverTest extends NativeTestCase
{
    private array $files = [];
    private array $attachments = [];
    private array $fieldKeys = [];

    public function set_up(): void
    {
        parent::set_up();
        $wp = new NativeWpService();
        (new SponsorUploads($wp))->addHooks();
        (new CreateReceiver($wp, new NativeAcfService()))->addHooks();
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
        foreach ($this->files as $file) {
            if (is_file($file)) { unlink($file); }
        }
        foreach ($this->fieldKeys as $key) {
            acf_remove_local_field($key);
        }
        if ($this->fieldKeys !== []) {
            // Removing a local group does not remove fields or their loaded name aliases.
            acf_get_store('fields')->reset();
        }
        parent::tear_down();
    }

    private function request(string $route = 'sponsor-offerings'): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/' . $route);
        $request->set_header('X-ACF-Rest-Upload-Version', '2');
        $request->set_body_params(['title' => 'Native version 2', 'status' => 'draft', 'acf' => [
            'image' => '$file:hero', 'date' => '20260915', 'time' => '12:00:00',
            'due_date' => '20260930', 'due_time' => '12:00:00', 'description' => 'Single image create',
            'contact_method' => ['mail'], 'organization_name' => 'Isolated organization',
            'organization_contact' => 'Test contact', 'organization_email' => 'contact@example.test',
            'organization_phone' => 123456, 'organization_number' => '123456-7890',
        ]]);
        $path = wp_tempnam('create-image.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $this->files[] = $path;
        $request->set_file_params(['_acf_rest_files' => [
            'name' => ['hero' => 'image.jpg'], 'type' => ['hero' => 'image/jpeg'],
            'tmp_name' => ['hero' => $path], 'error' => ['hero' => UPLOAD_ERR_OK],
            'size' => ['hero' => filesize($path)],
        ]]);
        return $request;
    }

    private function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        $_SERVER['REQUEST_METHOD'] = $request->get_method();
        $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        return rest_get_server()->dispatch($request);
    }

    public function testBothNativeCollectionsCreateWithOneImageWithoutAnIdempotencyKey(): void
    {
        foreach (['sponsor-offerings' => 'offering', 'sponsor-assignments' => 'assignment'] as $route => $type) {
            $response = $this->dispatch($this->request($route));
            self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
            $id = $response->get_data()['id'];
            self::assertSame($type, get_post_type($id));
            self::assertSame('Native version 2', get_post($id)->post_title);
            $image = (int) get_field('image', $id, false);
            self::assertGreaterThan(0, $image);
            self::assertSame('attachment', get_post_type($image));
            self::assertSame($id, (int) get_post($image)->post_parent);
            self::assertIsArray(getimagesize(get_attached_file($image)));
            self::assertFileIsReadable(get_attached_file($image));
            self::assertArrayHasKey('acf', $response->get_data());
        }
    }

    public function testNativePhpMultipartShapeCreates(): void
    {
        $request = $this->request();
        $boundary = 'native-create-profile';
        $body = '';
        foreach (explode('&', http_build_query($request->get_body_params())) as $pair) {
            [$name, $value] = array_map('urldecode', explode('=', $pair, 2));
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"_acf_rest_files[hero]\"; filename=\"image.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n" . file_get_contents(DIR_TESTDATA . '/images/canola.jpg') . "\r\n--$boundary--\r\n";
        $parsed = PhpMultipartParser::parse(['body' => $body, 'contentType' => 'multipart/form-data; boundary=' . $boundary]);
        self::assertTrue($parsed['files']['_acf_rest_files']['uploaded']['hero']);
        $file = &$parsed['files']['_acf_rest_files'];
        $path = $request->get_file_params()['_acf_rest_files']['tmp_name']['hero'];
        file_put_contents($path, base64_decode($file['content']['hero'], true));
        unset($file['content'], $file['uploaded']);
        $file['tmp_name']['hero'] = $path;
        $request->set_body_params($parsed['params']);
        $request->set_file_params($parsed['files']);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
    }

    private function ids(): array
    {
        return get_posts(['post_type' => ['attachment', 'assignment', 'offering'], 'post_status' => 'any',
            'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
    }

    #[DataProvider('invalidRequests')]
    public function testUnsupportedAndMalformedRequestsHaveNoSideEffects(string $case, int $status): void
    {
        $request = $this->request();
        $params = $request->get_body_params();
        $files = $request->get_file_params();
        switch ($case) {
            case 'update': $request->set_route('/wp/v2/sponsor-offerings/123'); break;
            case 'patch': $request->set_method('PATCH'); break;
            case 'delete': $request->set_method('DELETE'); break;
            case 'get': $request->set_method('GET'); break;
            case 'gallery': $params['acf']['image'] = ['$file:hero']; break;
            case 'nested': $params['acf']['image'] = ['child' => '$file:hero']; break;
            case 'file': $params['acf']['document'] = $params['acf']['image']; unset($params['acf']['image']); break;
            case 'conflicting': $params['title'] = '$file:hero'; break;
            case 'null-marker': $params['_acf_rest_nulls'] = ['acf[image]']; break;
            case 'empty-marker': $params['acf']['_acf_rest_empty'] = ['image']; break;
            case 'text-file-part': $params['_acf_rest_files'] = ['hero' => 'text']; break;
            case 'field-map': $params['acf']['_acf_field_key_map'] = ['image' => 'description']; break;
            case 'field-scope': $params['acf']['_acf_field_group_scope'] = ['group_other']; break;
            case 'field-alias':
                foreach (acf_get_fields('group_69a99cbe03b51') as $field) {
                    if ($field['name'] === 'image') { $params['acf'][$field['key']] = null; }
                }
                break;
            case 'missing-part': $files = []; break;
            case 'unexpected-part': $params['acf']['image'] = null; break;
            case 'extra-part': $files['other'] = $files['_acf_rest_files']; break;
            case 'part-scalar': $files['_acf_rest_files'] = 'bad'; break;
            case 'part-nested': $files['_acf_rest_files']['name']['hero'] = ['bad']; break;
            case 'part-keys': $files['_acf_rest_files']['name']['extra'] = 'extra.jpg'; break;
            case 'query-conflict': $request->set_query_params(['acf' => ['image' => 123]]); break;
            case 'missing-image': unset($params['acf']['image']); $files = []; break;
            case 'null-image': $params['acf']['image'] = null; $files = []; break;
            case 'bad-mime': file_put_contents($files['_acf_rest_files']['tmp_name']['hero'], 'not an image'); break;
            case 'ini-limit': $files['_acf_rest_files']['error']['hero'] = UPLOAD_ERR_INI_SIZE; break;
            case 'temporary-storage': $files['_acf_rest_files']['error']['hero'] = UPLOAD_ERR_NO_TMP_DIR; break;
            case 'write-failure': $files['_acf_rest_files']['error']['hero'] = UPLOAD_ERR_CANT_WRITE; break;
            case 'byte-limit':
                add_filter('upload_size_limit', static fn () => 32 * 1024 * 1024);
                file_put_contents($files['_acf_rest_files']['tmp_name']['hero'], str_repeat('x', 8388609));
                break;
            case 'parameter-limit': $params['title'] = str_repeat('x', 1048577); break;
        }
        $request->set_body_params($params);
        $request->set_file_params($files);
        $before = $this->ids();
        $response = $this->dispatch($request);
        self::assertSame($status, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame($before, $this->ids());
        self::assertSame([], $this->attachments, 'No media creation, including subsequently deleted attachments.');
    }

    public static function invalidRequests(): array
    {
        $cases = [];
        foreach (['update', 'patch', 'delete', 'get', 'gallery', 'nested', 'file', 'conflicting', 'null-marker',
            'empty-marker', 'text-file-part', 'missing-part', 'unexpected-part', 'extra-part', 'part-scalar',
            'part-nested', 'part-keys', 'query-conflict', 'missing-image', 'null-image', 'field-map', 'field-scope', 'field-alias'] as $case) {
            $cases[$case] = [$case, 400];
        }
        return $cases + ['bad-mime' => ['bad-mime', 415], 'ini-limit' => ['ini-limit', 413],
            'temporary-storage' => ['temporary-storage', 500], 'write-failure' => ['write-failure', 500],
            'byte-limit' => ['byte-limit', 413], 'parameter-limit' => ['parameter-limit', 413]];
    }

    #[DataProvider('permissions')]
    public function testNativeAndUploadPermissionsPrecedeMedia(string $role, bool $upload, int $expected): void
    {
        $user = self::factory()->user->create_and_get(['role' => $role]);
        if ($upload) { $user->add_cap('upload_files'); } else { $user->add_cap('upload_files', false); }
        wp_set_current_user($role === '' ? 0 : $user->ID);
        $before = $this->ids();
        self::assertSame($expected, $this->dispatch($this->request())->get_status());
        self::assertSame($before, $this->ids());
        self::assertSame([], $this->attachments);
    }

    public static function permissions(): array
    {
        return [['subscriber', true, 403], ['administrator', false, 403], ['', false, 401]];
    }

    #[DataProvider('eligibility')]
    public function testFieldEligibilityCannotBeBypassed(string $case): void
    {
        if ($case === 'type-hidden') {
            acf_get_field_type('image')->show_in_rest = false;
        } else {
            add_filter('acf/rest/get_fields', static function (array $fields) use ($case): array {
                foreach ($fields as $key => &$field) {
                    if ($field['name'] !== 'image') { continue; }
                    if ($case === 'filtered') { unset($fields[$key]); }
                    if ($case === 'opt-out') { $field['allow_multipart_rest_upload'] = 0; }
                    if ($case === 'file-type') { $field['type'] = 'file'; }
                    if ($case === 'nested-parent') { $field['parent'] = 'field_nested_parent'; }
                }
                return $fields;
            });
        }
        try {
            $before = $this->ids();
            self::assertSame(400, $this->dispatch($this->request())->get_status());
            self::assertSame($before, $this->ids());
            self::assertSame([], $this->attachments);
        } finally {
            acf_get_field_type('image')->show_in_rest = true;
        }
    }

    public static function eligibility(): array
    {
        return [['type-hidden'], ['filtered'], ['opt-out'], ['file-type'], ['nested-parent']];
    }

    #[DataProvider('imageLimits')]
    public function testImagePolicyOnlyTightensNativeLimits(array $native, array $policy, int $expected): void
    {
        add_filter('acf/rest/get_fields', static function (array $fields) use ($native): array {
            foreach ($fields as &$field) {
                if ($field['name'] === 'image') { $field = array_replace($field, $native); }
            }
            return $fields;
        });
        add_filter('AcfRestUpload/imagePolicy', static fn () => $policy);
        $before = $this->ids();
        self::assertSame($expected, $this->dispatch($this->request())->get_status());
        self::assertSame($before, $this->ids());
        self::assertSame([], $this->attachments);
    }

    public static function imageLimits(): array
    {
        return [
            'field-size-cannot-expand' => [['max_size' => 0.001], ['max_size' => 8], 413],
            'policy-size' => [[], ['max_size' => 0.001], 413],
            'field-mime-cannot-expand' => [['mime_types' => 'png'], ['mime_types' => 'jpg,png'], 415],
            'policy-mime' => [[], ['mime_types' => 'png'], 415],
            'max-width' => [[], ['max_width' => 1], 413],
            'min-height' => [[], ['min_height' => 100000], 400],
        ];
    }

    public function testActualBytesOverrideTheClaimedSize(): void
    {
        $request = $this->request();
        $files = $request->get_file_params();
        $files['_acf_rest_files']['size']['hero'] = 99999999;
        $request->set_file_params($files);
        self::assertSame(201, $this->dispatch($request)->get_status());
    }

    public function testPlatformLimitIsRetained(): void
    {
        add_filter('upload_size_limit', static fn () => 1);
        self::assertSame(413, $this->dispatch($this->request())->get_status());
        self::assertSame([], $this->attachments);
    }

    public function testValidExistingIdUsesNativeValidationWithoutUploading(): void
    {
        $image = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = $this->request();
        $acf = $request->get_param('acf');
        $acf['image'] = (string) $image;
        $request->set_param('acf', $acf);
        $request->set_file_params([]);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame($image, (int) get_field('image', $response->get_data()['id'], false));
        self::assertSame([$image], $this->attachments);
    }

    public function testOrdinaryValuesAreSanitizedBeforeNativePermissionChecks(): void
    {
        $request = $this->request();
        $request->set_param('parent', '0');
        $checked = false;
        $sanitized = 0;
        add_filter('rest_endpoints', static function (array $routes) use (&$checked, &$sanitized): array {
            foreach ($routes['/wp/v2/sponsor-offerings'] as &$handler) {
                if (!is_array($handler) || ($handler['methods'] ?? null) !== 'POST') { continue; }
                $permission = $handler['permission_callback'];
                $handler['permission_callback'] = static function ($actual) use ($permission, &$checked) {
                    self::assertSame(0, $actual->get_param('parent'));
                    $checked = true;
                    return $permission($actual);
                };
                $handler['args']['parent']['sanitize_callback'] = static function ($value, $actual, $param) use (&$sanitized) {
                    $sanitized++;
                    return rest_sanitize_request_arg($value, $actual, $param);
                };
            }
            return $routes;
        });
        self::assertSame(201, $this->dispatch($request)->get_status());
        self::assertTrue($checked);
        self::assertSame(1, $sanitized, 'Ordinary sanitizers run once, as in native dispatch.');
    }

    public function testUnregisteredAndNoHeaderRequestsRemainNative(): void
    {
        $unregistered = new WP_REST_Request('GET', '/wp/v2/types');
        $unregistered->set_header('X-ACF-Rest-Upload-Version', '2');
        self::assertSame(200, $this->dispatch($unregistered)->get_status());
        $request = $this->request();
        $request->remove_header('X-ACF-Rest-Upload-Version');
        self::assertSame(400, $this->dispatch($request)->get_status());
        self::assertSame([], $this->attachments);
    }

    public function testVersionTwoNeverStartsLegacyOperations(): void
    {
        (new \ApiSponsorManager\AcfRestUpload\Receiver())->addHooks();
        add_action('AcfRestUpload/beginOperation', static function (): void { self::fail('Version 2 entered the legacy store.'); });
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $request = $this->request();
            $request->set_header('Idempotency-Key', 'ignored-by-version-two');
            $response = $this->dispatch($request);
            self::assertSame(201, $response->get_status());
            $ids[] = $response->get_data()['id'];
            self::assertFalse(metadata_exists('post', $ids[$i], '_acf_rest_upload_create_identity'));
        }
        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testNativeImageValidationRunsAgainAndDeletesRejectedUpload(): void
    {
        add_filter('acf/validate_rest_value/type=image', static function ($valid, $value) {
            return $value ? new \WP_Error('native_image_rejected', 'Rejected by native image validation.') : $valid;
        }, 20, 2);
        $before = $this->ids();
        $response = $this->dispatch($this->request());
        self::assertSame(400, $response->get_status());
        self::assertCount(1, $this->attachments);
        self::assertSame($before, $this->ids());
    }

    public function testStorageFailureIsControlledAndRemovesOnlyTheMovedFile(): void
    {
        $moved = [];
        add_filter('pre_move_uploaded_file', static function ($move, $file, $destination) use (&$moved) {
            $moved[] = $destination;
            return $move;
        }, 10, 3);
        add_filter('wp_insert_post_empty_content', static fn ($empty, $post) => $post['post_type'] === 'attachment' || $empty, 10, 2);
        $before = $this->ids();
        self::assertSame(500, $this->dispatch($this->request())->get_status());
        self::assertCount(1, $moved);
        self::assertFileDoesNotExist($moved[0]);
        self::assertSame($before, $this->ids());
    }

    public function testRegisteredNativeDestinationAllowsAnOmittedOptionalImageOrNull(): void
    {
        $this->fieldKeys[] = 'field_create_optional';
        acf_add_local_field_group([
            'key' => 'group_create_optional', 'title' => 'Optional image', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'fields' => [['key' => 'field_create_optional', 'name' => 'image', 'type' => 'image',
                'required' => 0, 'allow_multipart_rest_upload' => 1]],
        ]);
        add_filter('AcfRestUpload/destinations', static fn ($routes) => $routes + [
            '/wp/v2/posts' => ['post_type' => 'post', 'image_field' => 'image'],
        ]);
        try {
            foreach ([[], ['image' => null]] as $acf) {
                $request = new WP_REST_Request('POST', '/wp/v2/posts');
                $request->set_header('X-ACF-Rest-Upload-Version', '2');
                $request->set_body_params(['title' => 'Optional image', 'acf' => $acf]);
                $response = $this->dispatch($request);
                self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
                self::assertSame('post', get_post_type($response->get_data()['id']));
            }
            self::assertSame([], $this->attachments);
        } finally {
            acf_remove_local_field_group('group_create_optional');
        }
    }

    public function testBothSponsorRoutesKeepRequiredImageValidation(): void
    {
        foreach (['sponsor-assignments', 'sponsor-offerings'] as $route) {
            $request = $this->request($route);
            $acf = $request->get_param('acf');
            unset($acf['image']);
            $request->set_param('acf', $acf);
            $request->set_file_params([]);
            self::assertSame(400, $this->dispatch($request)->get_status());
        }
        self::assertSame([], $this->ids());
        self::assertSame([], $this->attachments);
    }

    public function testFailedCleanupIsAnErrorNotAClientRejection(): void
    {
        add_filter('acf/validate_rest_value/type=image', static fn ($valid, $value) => $value
            ? new \WP_Error('native_image_rejected', 'Rejected by native image validation.') : $valid, 20, 2);
        $denyDelete = static fn () => false;
        add_filter('pre_delete_attachment', $denyDelete);
        try {
            $response = $this->dispatch($this->request());
            self::assertSame(500, $response->get_status());
            self::assertSame('acf_rest_upload_cleanup_failed', $response->get_data()['code']);
            self::assertCount(1, $this->attachments);
        } finally {
            remove_filter('pre_delete_attachment', $denyDelete);
        }
    }

    #[DataProvider('groupEligibility')]
    public function testDestinationFieldMustBeUniqueAndInAnExposedGroup(bool $exposed, int $expected): void
    {
        $group = 'group_create_duplicate_' . (int) $exposed;
        $this->fieldKeys[] = 'field_create_duplicate_' . (int) $exposed;
        acf_add_local_field_group([
            'key' => $group, 'title' => 'Duplicate image', 'show_in_rest' => $exposed,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'offering']]],
            'fields' => [['key' => 'field_create_duplicate_' . (int) $exposed, 'name' => 'image', 'type' => 'image',
                'allow_multipart_rest_upload' => 1]],
        ]);
        try {
            $response = $this->dispatch($this->request());
            self::assertSame($expected, $response->get_status(), wp_json_encode($response->get_data()));
            self::assertCount($expected === 201 ? 1 : 0, $this->attachments);
        } finally {
            acf_remove_local_field_group($group);
        }
    }

    public static function groupEligibility(): array
    {
        return [[false, 201], [true, 400]];
    }
}
