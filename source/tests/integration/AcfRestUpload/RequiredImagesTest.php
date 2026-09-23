<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\Recovery;
use ApiSponsorManager\Test\NativeTestCase;
use WP_REST_Request;

final class RequiredImagesTest extends NativeTestCase
{
    private array $temporary = [];
    private array $attachments = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_post_type('image_proof', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
        acf_add_local_field_group(['key' => 'group_image_proof', 'title' => 'Required', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'image_proof']]], 'fields' => [
                ['key' => 'field_proof_a', 'name' => 'image_a', 'type' => 'image', 'required' => 1],
                ['key' => 'field_proof_b', 'name' => 'image_b', 'type' => 'image', 'required' => 1],
            ]]);
        add_action('add_attachment', function (int $id): void { $this->attachments[] = $id; });
    }

    public function tear_down(): void
    {
        foreach ($this->attachments as $id) { wp_delete_attachment($id, true); }
        foreach ($this->temporary as $path) { if (is_file($path)) { unlink($path); } }
        foreach (['field_proof_a', 'field_proof_b'] as $key) { acf_remove_local_field($key); }
        acf_remove_local_field_group('group_image_proof');
        acf_get_store('fields')->reset();
        unregister_post_type('image_proof');
        parent::tear_down();
    }

    public function testNativeCreateReceivesAttachmentIdsAndCompletes(): void
    {
        $request = $this->request();
        $this->image($request, ['image_a', 'image_b']);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertCount(2, $this->attachments);
        foreach (['image_a', 'image_b'] as $index => $name) {
            self::assertSame($this->attachments[$index], (int) get_post_meta($response->get_data()['id'], $name, true));
            self::assertSame('', get_post_meta($this->attachments[$index], Recovery::CLEANUP, true));
        }
    }

    public function testUploadPermissionAndSecondUploadFailureDoNotCreatePosts(): void
    {
        $denied = $this->request();
        $this->image($denied, ['image_a', 'image_b']);
        $deny = static function ($caps) { $caps['upload_files'] = false; return $caps; };
        add_filter('user_has_cap', $deny);
        self::assertSame('acf_rest_upload_forbidden', $this->dispatch($denied)->get_data()['code']);
        remove_filter('user_has_cap', $deny);
        self::assertSame([], $this->attachments);
        $request = $this->request();
        $this->image($request, ['image_a', 'image_b']);
        add_filter('wp_handle_sideload_prefilter', function (array $file): array {
            if ($this->attachments !== []) { $file['error'] = 'controlled'; }
            return $file;
        });
        $response = $this->dispatch($request);
        self::assertSame('acf_rest_upload_storage_failed', $response->get_data()['code']);
        self::assertCount(1, $this->attachments);
        self::assertSame('1', get_post_meta($this->attachments[0], Recovery::CLEANUP, true));
        self::assertSame([], get_posts(['post_type' => 'image_proof', 'post_status' => 'any']));
    }

    private function request(): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/image_proof');
        $request->set_header('X-ACF-Rest-Upload-Version', '4');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=proof');
        $request->set_body_params(['title' => 'Images']);
        return $request;
    }

    private function image(WP_REST_Request $request, array $names): void
    {
        foreach ($names as $name) {
            $path = wp_tempnam('proof.jpg'); copy(DIR_TESTDATA . '/images/canola.jpg', $path); $this->temporary[] = $path;
            foreach (['name' => "$name.jpg", 'type' => 'image/jpeg', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK] as $attribute => $value) { $parts[$attribute][$name] = $value; }
        }
        $request->set_file_params(['acf' => $parts]);
    }

    private function dispatch(WP_REST_Request $request): \WP_REST_Response
    {
        $_SERVER['REQUEST_METHOD'] = $request->get_method(); $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        unset($GLOBALS['wp_rest_additional_fields']['image_proof']['acf']);
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request); $GLOBALS['wp_rest_server'] = null;
        return rest_get_server()->dispatch($request);
    }
}
