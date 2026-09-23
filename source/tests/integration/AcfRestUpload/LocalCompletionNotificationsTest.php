<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Local completion notifications for native sponsor creates.
 */
class LocalCompletionNotificationsTest extends NativeTestCase
{
    private array $files = [];
    private array $attachments = [];
    private array $fieldKeys = [];
    private array $mail = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_action('add_attachment', function (int $id): void { $this->attachments[] = $id; });
        add_action('doing_it_wrong_run', function (string $function, string $message): void {
            if ($function === 'rest_handle_multi_type_schema') {
                self::assertStringContainsString('acf[contact_method]', $message);
                $this->setExpectedIncorrectUsage($function);
            }
        }, 10, 2);
        update_field('field_69bbaf272549d', [
            ['trigger' => 'submit', 'post_type' => 'offering', 'recipients' => 'recipient+{ID}@example.test',
                'subject' => 'Submitted {ID}', 'message' => 'Saved {ID}'],
            ['trigger' => 'submit', 'post_type' => 'assignment', 'recipients' => 'recipient+{ID}@example.test',
                'subject' => 'Submitted {ID}', 'message' => 'Saved {ID}'],
        ], 'options');
        add_filter('pre_wp_mail', function ($return, array $attributes) {
            $this->mail[] = $attributes;
            return true;
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
        $request->set_header('X-ACF-Rest-Upload-Version', '4');
        $request->set_body_params(['title' => 'Native version 2', 'status' => 'draft', 'acf' => [
            'image' => 0, 'date' => '20260915', 'time' => '12:00:00',
            'due_date' => '20260930', 'due_time' => '12:00:00', 'description' => 'Single image create',
            'contact_method' => ['mail'], 'organization_name' => 'Isolated organization',
            'organization_contact' => 'Test contact', 'organization_email' => 'contact@example.test',
            'organization_phone' => 123456, 'organization_number' => '123456-7890',
        ]]);
        $path = wp_tempnam('create-image.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $this->files[] = $path;
        $request->set_file_params(['acf' => [
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
        // Simulate a fresh HTTP request, not ACF's cached schema from an earlier item/collection.
        foreach (array_keys($GLOBALS['wp_rest_additional_fields'] ?? []) as $type) {
            unset($GLOBALS['wp_rest_additional_fields'][$type]['acf']);
        }
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        // ACF registers route-specific schema. Do not reuse previously built endpoint arguments.
        $GLOBALS['wp_rest_server'] = null;
        return rest_get_server()->dispatch($request);
    }

    public function testOrdinaryJsonKeepsItsLocalCompletionNotificationBehavior(): void
    {
        $image = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = $this->request();
        $params = $request->get_body_params();
        $params['acf']['image'] = $image;
        $request->remove_header('X-ACF-Rest-Upload-Version');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body_params([]);
        $request->set_file_params([]);
        $request->set_body(wp_json_encode($params));
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        $id = $response->get_data()['id'];
        self::assertSame($image, (int) get_field('image', $id, false));
        self::assertSame([], $this->mail, 'Ordinary JSON does not use version-2 completion.');
        do_action('ModularityFrontendForm/afterInsertPost', $id);
        do_action('ModularityFrontendForm/afterInsertPost', $id);
        self::assertSame([['recipient+' . $id . '@example.test']], array_column($this->mail, 'to'));
    }

    public function testLocalDatabaseCompletionHookNotifiesAfterMetadataSaving(): void
    {
        update_field('field_69bbaf272549d', [
            ['trigger' => 'submit', 'post_type' => 'offering', 'recipients' => 'local@example.test',
                'subject' => '{description}', 'message' => 'Saved {ID}'],
        ], 'options');
        $id = wp_insert_post(['post_type' => 'offering', 'post_status' => 'draft', 'post_title' => 'Local create']);
        self::assertGreaterThan(0, $id);
        self::assertSame([], $this->mail);
        update_field('description', 'Metadata saved before local completion', $id);
        do_action('ModularityFrontendForm/afterInsertPost', $id);
        do_action('ModularityFrontendForm/afterInsertPost', $id);
        self::assertCount(1, $this->mail);
        self::assertSame(['local@example.test'], $this->mail[0]['to']);
        self::assertSame('Metadata saved before local completion', $this->mail[0]['subject']);
    }
}
