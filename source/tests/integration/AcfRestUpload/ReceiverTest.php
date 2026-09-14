<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\Receiver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Requires the WordPress core test bootstrap, ACF PRO and this plugin's autoloader.
 * Kept separate from the standalone PHPUnit suite because it creates real media.
 */
class ReceiverTest extends WP_UnitTestCase
{
    private Receiver $receiver;
    private bool $allowed = true;
    private bool $saved = false;
    private bool $rejectAttachment = false;
    private array $validated = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        acf_add_local_field_group([
            'key' => 'group_upload_regression',
            'title' => 'Upload regression',
            'show_in_rest' => 1,
            'fields' => [[
                'key' => 'field_upload_regression_image',
                'name' => 'image',
                'type' => 'image',
                'required' => 1,
                'allow_multipart_rest_upload' => 1,
            ]],
        ]);
        $this->receiver = new Receiver();
        $this->receiver->addHooks();
        foreach (['sponsor-offerings', 'sponsor-assignments'] as $type) {
            register_rest_route('wp/v2', '/' . $type, [
                'methods' => 'POST',
                'permission_callback' => fn() => $this->allowed,
                'args' => ['acf' => [
                    'validate_callback' => function ($value) {
                        $this->validated[] = $value['image'] ?? null;
                        $field = acf_get_field('field_upload_regression_image');
                        $field['prefix'] = 'acf';
                        if ($this->rejectAttachment && !empty($value['image'])) {
                            return new WP_Error('rest_invalid_param', 'Rejected attachment');
                        }
                        return acf_get_field_type('image')->validate_rest_value(true, $value['image'] ?? null, $field);
                    },
                ]],
                'callback' => function ($request) {
                    $this->saved = true;
                    return new WP_REST_Response(['id' => 123, 'acf' => $request['acf']], 201);
                },
            ], true);
        }
    }

    public function tear_down(): void
    {
        remove_filter('rest_pre_dispatch', [$this->receiver, 'preDispatch'], 1);
        remove_filter('rest_request_before_callbacks', [$this->receiver, 'beforeCallbacks'], 10);
        remove_filter('rest_dispatch_request', [$this->receiver, 'dispatchRequest'], 10);
        remove_filter('rest_post_dispatch', [$this->receiver, 'postDispatch'], 10);
        acf_remove_local_field_group('group_upload_regression');
        parent::tear_down();
    }

    private function request(string $type = 'sponsor-offerings'): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/' . $type);
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', wp_generate_uuid4());
        $request->set_body_params(['acf' => ['image' => '$file:hero']]);
        $path = wp_tempnam('test-image.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $request->set_file_params(['_acf_rest_files' => ['hero' => [
            'name' => 'test-image.jpg', 'type' => 'image/jpeg',
            'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path),
        ]]]);
        return $request;
    }

    public function testRequiredImageIsRevalidatedWithRealAttachmentForBothRoutes(): void
    {
        foreach (['sponsor-offerings', 'sponsor-assignments'] as $type) {
            $this->validated = [];
            $request = $this->request($type);
            $response = rest_get_server()->dispatch($request);
            self::assertSame(201, $response->get_status());
            self::assertNull($this->validated[0]);
            $id = $response->get_data()['acf']['image'];
            self::assertGreaterThan(0, $id);
            self::assertSame($id, $this->validated[1]);
            self::assertSame('attachment', get_post_type($id));
            self::assertTrue($this->saved);
            wp_delete_attachment($id, true);
            $this->receiver->postDispatch(new WP_Error('test_cleanup'), null, $request);
        }
    }

    public function testFinalValidationFailureRemovesAttachmentAndDoesNotSave(): void
    {
        $this->rejectAttachment = true;
        $request = $this->request();
        $response = rest_get_server()->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertFalse($this->saved);
        self::assertGreaterThan(0, $this->validated[1]);
        self::assertNull(get_post($this->validated[1]));
        $this->receiver->postDispatch($response, null, $request);
    }

    public function testPermissionDenialDoesNotResolveUpload(): void
    {
        $this->allowed = false;
        $request = $this->request();
        $response = rest_get_server()->dispatch($request);
        self::assertSame(403, $response->get_status());
        self::assertSame([null], $this->validated);
        self::assertFalse($this->saved);
        $this->receiver->postDispatch($response, null, $request);
        unlink($request->get_file_params()['_acf_rest_files']['hero']['tmp_name']);
    }

    public function testMissingImageWithoutProtocolRetainsNativeError(): void
    {
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_body_params(['acf' => ['image' => null]]);
        self::assertSame(400, rest_get_server()->dispatch($request)->get_status());
        self::assertFalse($this->saved);
    }

    public function testUnrelatedValidationErrorIsPreserved(): void
    {
        $request = $this->request();
        $this->receiver->preDispatch(null, rest_get_server(), $request);
        $error = new WP_Error('rest_invalid_param', 'Invalid title', [
            'status' => 400, 'params' => ['title' => 'Invalid title'],
        ]);
        self::assertSame($error, $this->receiver->beforeCallbacks($error, [], $request));
        self::assertFalse($this->saved);
        $this->receiver->postDispatch($error, null, $request);
        unlink($request->get_file_params()['_acf_rest_files']['hero']['tmp_name']);
    }

    public function testMissingRequiredImageWithProtocolIsNotDeferred(): void
    {
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', wp_generate_uuid4());
        $request->set_body_params(['acf' => ['image' => null]]);
        $response = rest_get_server()->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertFalse($this->saved);
        $this->receiver->postDispatch($response, null, $request);
    }
}
