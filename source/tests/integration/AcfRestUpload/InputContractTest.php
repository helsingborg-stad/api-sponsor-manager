<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use WP_REST_Posts_Controller;
use WP_REST_Request;

class CompatibleInputController extends WP_REST_Posts_Controller {}

final class InputContractTest extends NativeTestCase
{
    private array $temporary = [];
    private array $attachments = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_post_type('input_contract', ['public' => true, 'show_in_rest' => true, 'rest_controller_class' => CompatibleInputController::class, 'supports' => ['title']]);
        acf_add_local_field_group(['key' => 'group_input', 'title' => 'Input', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'input_contract']]], 'fields' => [
                ['key' => 'field_input_a', 'name' => 'image_a', 'type' => 'image'],
                ['key' => 'field_input_b', 'name' => 'image_b', 'type' => 'image'],
                ['key' => 'field_input_location', 'name' => 'location', 'type' => 'google_map'],
        ]]);
        add_action('add_attachment', function (int $id): void { $this->attachments[] = $id; });
        add_action('doing_it_wrong_run', function (string $function, string $message): void {
            if ($function === 'rest_handle_multi_type_schema') {
                self::assertStringContainsString('acf[location]', $message);
                $this->setExpectedIncorrectUsage($function);
            }
        }, 10, 2);
    }

    public function tear_down(): void
    {
        foreach ($this->attachments as $id) { wp_delete_attachment($id, true); }
        foreach ($this->temporary as $path) { if (is_file($path)) { unlink($path); } }
        acf_remove_local_field_group('group_input');
        foreach (['field_input_a', 'field_input_b', 'field_input_location'] as $key) { acf_remove_local_field($key); }
        acf_get_store('fields')->reset();
        unset($GLOBALS['wp_rest_additional_fields']['input_contract']);
        unregister_post_type('input_contract');
        parent::tear_down();
    }

    public function testNativeFieldsAndGoogleMapPersistWithoutFiles(): void
    {
        $location = ['address' => 'Address', 'lat' => '56.1', 'lng' => '12.2', 'name' => 'Name', 'city' => 'City', 'state' => 'State', 'post_code' => '12345', 'country' => 'SE'];
        $response = $this->dispatch($this->request(['title' => 'Native fields', 'status' => 'draft', 'acf' => ['location' => $location]]));
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame('Native fields', get_post($response->get_data()['id'])->post_title);
        self::assertSame($location, get_post_meta($response->get_data()['id'], 'location', true));
        self::assertSame([], $this->attachments);
    }

    public function testTwoNativeFileDestinationsAndExistingIdReachNativeAcf(): void
    {
        $existing = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $this->attachments = [];
        $request = $this->request(['title' => 'Images', 'acf' => ['image_a' => $existing]]);
        $this->image($request, 'image_b');
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertCount(1, $this->attachments);
        self::assertSame($existing, (int) get_post_meta($response->get_data()['id'], 'image_a', true));
        self::assertSame($this->attachments[0], (int) get_post_meta($response->get_data()['id'], 'image_b', true));
        wp_delete_attachment($existing, true);
    }

    public function testV3AndAmbiguousOrNestedFilesAreRejectedBeforeStorage(): void
    {
        $v3 = $this->request();
        $v3->set_header('X-ACF-Rest-Upload-Version', '3');
        self::assertSame('acf_rest_upload_unsupported_version', $this->dispatch($v3)->get_data()['code']);
        $ambiguous = $this->request(['acf' => ['image_a' => 494]]);
        $this->image($ambiguous, 'image_a');
        self::assertSame('acf_rest_upload_ambiguous_image', $this->dispatch($ambiguous)->get_data()['code']);
        $nested = $this->request();
        $this->image($nested, 'image_a');
        $parts = $nested->get_file_params();
        foreach ($parts['acf'] as &$attribute) { $attribute = ['nested' => $attribute]; }
        $nested->set_file_params($parts);
        self::assertSame('acf_rest_upload_invalid_parts', $this->dispatch($nested)->get_data()['code']);
        self::assertSame([], $this->attachments);
    }

    private function request(array $values = ['title' => 'Native']): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/input_contract');
        $request->set_header('X-ACF-Rest-Upload-Version', '4');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=input');
        $request->set_body_params($values);
        return $request;
    }

    private function image(WP_REST_Request $request, string $name): void
    {
        $path = wp_tempnam('input.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $this->temporary[] = $path;
        foreach (['name' => 'input.jpg', 'type' => 'image/jpeg', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK] as $attribute => $value) {
            $parts[$attribute][$name] = $value;
        }
        $request->set_file_params(['acf' => $parts]);
    }

    private function dispatch(WP_REST_Request $request): \WP_REST_Response
    {
        $_SERVER['REQUEST_METHOD'] = $request->get_method();
        $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        unset($GLOBALS['wp_rest_additional_fields']['input_contract']['acf']);
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        $GLOBALS['wp_rest_server'] = null;
        return rest_get_server()->dispatch($request);
    }
}
