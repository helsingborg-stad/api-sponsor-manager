<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use WP_REST_Request;
use WP_REST_Response;

/** Native fixtures: no changes to production required fields or validation. */
class RequiredImagesTest extends NativeTestCase
{
    private array $temporary = [];
    private array $attachments = [];
    private array $mail = [];
    private array $events = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_post_type('image_proof', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'author']]);
        acf_add_local_field_group([
            'key' => 'group_image_proof', 'title' => 'Required images', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'image_proof']]],
            'fields' => [
                ['key' => 'field_proof_a', 'name' => 'image_a', 'label' => 'First', 'type' => 'image', 'required' => 1],
                ['key' => 'field_proof_b', 'name' => 'image_b', 'label' => 'Second', 'type' => 'image', 'required' => 1],
                ['key' => 'field_proof_number', 'name' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
            ],
        ]);
        update_field('field_69bbaf272549d', [[
            'trigger' => 'submit', 'post_type' => 'image_proof', 'recipients' => 'proof@example.test',
            'subject' => 'Saved {quantity}', 'message' => 'Saved {ID}',
        ]], 'options');
        add_filter('pre_wp_mail', function ($result, $attributes) {
            $this->events[] = 'mail';
            $this->mail[] = $attributes;
            return true;
        }, 10, 2);
        add_action('add_attachment', function (int $id): void {
            $this->attachments[] = $id;
            $this->events[] = 'attachment';
        });
        add_filter('acf/validate_rest_value/type=image', function ($valid, $value, $field) {
            if (in_array($field['key'], ['field_proof_a', 'field_proof_b'], true)) {
                self::assertTrue($valid, 'Native image validation must pass before this observer.');
                self::assertIsInt($value);
                self::assertSame('attachment', get_post_type($value));
                $this->events[] = 'validate:' . $field['name'];
            }
            return $valid;
        }, PHP_INT_MAX, 3);
        add_action('rest_insert_image_proof', function (): void { $this->events[] = 'insert'; });
        add_action('rest_after_insert_image_proof', function (): void { $this->events[] = 'saved'; });
    }

    public function tear_down(): void
    {
        foreach ($this->attachments as $id) { wp_delete_attachment($id, true); }
        foreach ($this->temporary as $path) {
            if (is_file($path)) { unlink($path); }
        }
        foreach (['field_proof_a', 'field_proof_b', 'field_proof_number'] as $key) { acf_remove_local_field($key); }
        acf_remove_local_field_group('group_image_proof');
        acf_get_store('fields')->reset();
        unregister_post_type('image_proof');
        parent::tear_down();
    }

    private function request(): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/image_proof');
        $request->set_header('X-ACF-Rest-Upload-Version', '3');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=proof');
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode([
            'title' => 'Two required images', 'status' => 'draft',
            'acf' => ['image_a' => '$file:first', 'image_b' => '$file:second', 'quantity' => 7],
        ])]);
        $parts = [];
        foreach (['first', 'second'] as $key) {
            $path = wp_tempnam('proof.jpg');
            copy(DIR_TESTDATA . '/images/canola.jpg', $path);
            $this->temporary[] = $path;
            foreach (['name' => $key . '.jpg', 'type' => 'image/jpeg', 'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK, 'size' => filesize($path)] as $attribute => $value) {
                $parts[$attribute][$key] = $value;
            }
        }
        $request->set_file_params(['_acf_rest_files' => $parts]);
        return $request;
    }

    private function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        $temporary = $request->get_file_params()['_acf_rest_files']['tmp_name'] ?? [];
        add_filter('pre_move_uploaded_file', function ($move, $file) use ($temporary) {
            if (in_array($file['tmp_name'], $temporary, true)) {
                self::assertContains('permission', $this->events, 'Native permission must precede permanent file movement.');
                $this->events[] = 'move';
            }
            return $move;
        }, 10, 2);
        $_SERVER['REQUEST_METHOD'] = $request->get_method();
        $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        foreach (array_keys($GLOBALS['wp_rest_additional_fields'] ?? []) as $type) {
            unset($GLOBALS['wp_rest_additional_fields'][$type]['acf']);
        }
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        $GLOBALS['wp_rest_server'] = null;
        // Observe the real registered permission callback, without replacing its decision.
        add_filter('rest_endpoints', function (array $routes): array {
            foreach ($routes['/wp/v2/image_proof'] as &$handler) {
                if (!is_array($handler) || ($handler['callback'][1] ?? null) !== 'create_item') { continue; }
                $permission = $handler['permission_callback'];
                $handler['permission_callback'] = function ($actual) use ($permission) {
                    $result = $permission($actual);
                    $this->events[] = 'permission';
                    return $result;
                };
            }
            return $routes;
        });
        return rest_get_server()->dispatch($request);
    }

    public function testTwoRequiredImagesPassNativeValidationAndPersist(): void
    {
        $response = $this->dispatch($this->request());
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        $id = $response->get_data()['id'];
        self::assertSame('image_proof', get_post_type($id));
        self::assertSame('Two required images', get_post($id)->post_title);
        self::assertCount(2, $this->attachments);
        foreach (['image_a', 'image_b'] as $index => $name) {
            $attachment = $this->attachments[$index];
            self::assertSame($attachment, (int) get_post_meta($id, $name, true));
            self::assertSame($id, (int) get_post($attachment)->post_parent);
            self::assertFileIsReadable(get_attached_file($attachment));
            self::assertSame(hash_file('sha256', DIR_TESTDATA . '/images/canola.jpg'), hash_file('sha256', get_attached_file($attachment)));
        }
        self::assertCount(1, $this->mail);
        self::assertSame('Saved 7', $this->mail[0]['subject']);
        self::assertSame(['permission', 'move', 'attachment', 'move', 'attachment', 'validate:image_a', 'validate:image_b',
            'permission', 'insert', 'saved', 'mail'], $this->events);
    }

    public function testSecondUploadFailureRemovesFirstAndDoesNotNotify(): void
    {
        $paths = [];
        add_filter('wp_handle_sideload_prefilter', function (array $file) use (&$paths): array {
            if ($this->attachments !== []) {
                $paths[] = get_attached_file($this->attachments[0]);
                $file['error'] = 'Controlled second upload failure';
            }
            return $file;
        });
        $response = $this->dispatch($this->request());
        self::assertSame(500, $response->get_status());
        self::assertSame('acf_rest_upload_storage_failed', $response->get_data()['code']);
        self::assertCount(1, $this->attachments);
        self::assertNull(get_post($this->attachments[0]));
        self::assertCount(1, $paths);
        self::assertFileDoesNotExist($paths[0]);
        self::assertSame([], get_posts(['post_type' => 'image_proof', 'post_status' => 'any']));
        self::assertSame([], $this->mail);
    }

    public function testNativePermissionDenialPrecedesPermanentMedia(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $response = $this->dispatch($this->request());
        self::assertSame(403, $response->get_status());
        self::assertSame('rest_cannot_create', $response->get_data()['code']);
        self::assertSame(['permission'], $this->events);
        self::assertSame([], $this->attachments);
        self::assertSame([], $this->mail);
    }

    public function testUnrelatedNativeAcfValidationStillRejectsTheCreate(): void
    {
        $request = $this->request();
        $payload = json_decode($request->get_body_params()['_acf_rest_payload'], true);
        $payload['acf']['quantity'] = 'not a number';
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode($payload)]);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code']);
        self::assertStringContainsString('quantity', wp_json_encode($response->get_data()));
        foreach ($this->attachments as $id) { self::assertNull(get_post($id)); }
        self::assertSame([], $this->mail);
    }

    public function testHeaderlessNativeCreateKeepsExistingImages(): void
    {
        $image = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $request = new WP_REST_Request('POST', '/wp/v2/image_proof');
        $request->set_body_params(['title' => 'Native', 'acf' => ['image_a' => $image, 'image_b' => $image]]);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        // ACF's native image save parents an unparented existing image.
        self::assertSame($response->get_data()['id'], (int) get_post($image)->post_parent);
        self::assertSame($image, (int) get_post_meta($response->get_data()['id'], 'image_b', true));
        self::assertSame([], $this->mail);
    }

    public function testRequiredRulesAreUnchangedAndRejectAMissingSecondImage(): void
    {
        $request = $this->request();
        $payload = json_decode($request->get_body_params()['_acf_rest_payload'], true);
        unset($payload['acf']['image_b']);
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode($payload)]);
        $parts = $request->get_file_params();
        foreach ($parts['_acf_rest_files'] as &$values) { unset($values['second']); }
        $request->set_file_params($parts);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code']);
        self::assertStringContainsString('image_b', wp_json_encode($response->get_data()));
        self::assertSame(1, acf_get_field('field_proof_a')['required']);
        self::assertSame(1, acf_get_field('field_proof_b')['required']);
        foreach ($this->attachments as $id) { self::assertNull(get_post($id)); }
        self::assertSame([], $this->mail);
    }

    public function testNativeImageRejectionRemovesBothUploads(): void
    {
        add_filter('acf/validate_rest_value/type=image', static function ($valid, $value, $field) {
            return $field['key'] === 'field_proof_b' ? new \WP_Error('native_image_rejected', 'Native rejection') : $valid;
        }, PHP_INT_MAX, 3);
        $response = $this->dispatch($this->request());
        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('native_image_rejected', wp_json_encode($response->get_data()));
        self::assertCount(2, $this->attachments);
        foreach ($this->attachments as $id) { self::assertNull(get_post($id)); }
        self::assertSame([], $this->mail);
    }

    public function testOrdinaryValuesAreSanitizedForEarlyNativePermission(): void
    {
        $request = $this->request();
        $payload = json_decode($request->get_body_params()['_acf_rest_payload'], true);
        $payload['author'] = (string) get_current_user_id();
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode($payload)]);
        $seen = [];
        add_filter('rest_endpoints', static function ($routes) use (&$seen) {
            foreach ($routes['/wp/v2/image_proof'] as &$handler) {
                if (!is_array($handler) || ($handler['callback'][1] ?? null) !== 'create_item') { continue; }
                $permission = $handler['permission_callback'];
                $handler['permission_callback'] = static function ($actual) use ($permission, &$seen) {
                    $seen[] = $actual->get_param('author');
                    return $permission($actual);
                };
            }
            return $routes;
        });
        self::assertSame(201, $this->dispatch($request)->get_status());
        self::assertSame([get_current_user_id(), get_current_user_id()], $seen);
    }

    public function testUploadPermissionDenialPrecedesPermanentMedia(): void
    {
        add_filter('user_has_cap', static function ($caps) { $caps['upload_files'] = false; return $caps; });
        $response = $this->dispatch($this->request());
        self::assertSame(403, $response->get_status());
        self::assertSame('acf_rest_upload_forbidden', $response->get_data()['code']);
        self::assertSame(['permission'], $this->events);
        self::assertSame([], $this->attachments);
        self::assertSame([], $this->mail);
    }

    public function testRetiredProviderPolicyDoesNotControlNativeImages(): void
    {
        add_filter('AcfRestUpload/imagePolicy', static fn () => ['max_width' => 1]);
        self::assertSame(201, $this->dispatch($this->request())->get_status());
    }

    public function testSecondFieldByteLimitRejectsBeforeEitherUpload(): void
    {
        add_filter('acf/load_field/key=field_proof_b', static function ($field) {
            $field['max_width'] = 1;
            return $field;
        });
        $response = $this->dispatch($this->request());
        self::assertSame(413, $response->get_status());
        self::assertSame('acf_rest_upload_too_large', $response->get_data()['code']);
        self::assertSame([], $this->attachments);
        self::assertSame([], $this->mail);
    }

    public function testSavedFieldMismatchPreventsCompletionAndRemovesOwnedResources(): void
    {
        $postId = null;
        $paths = [];
        add_action('rest_after_insert_image_proof', function ($post) use (&$postId, &$paths): void {
            $postId = $post->ID;
            foreach ($this->attachments as $id) { $paths[] = get_attached_file($id); }
            update_post_meta($postId, 'image_b', 0);
        });
        $response = $this->dispatch($this->request());
        self::assertSame(500, $response->get_status());
        self::assertSame('acf_rest_upload_storage_failed', $response->get_data()['code']);
        self::assertIsInt($postId);
        self::assertNull(get_post($postId));
        self::assertCount(2, $paths);
        foreach ($paths as $path) { self::assertFileDoesNotExist($path); }
        foreach ($this->attachments as $id) { self::assertNull(get_post($id)); }
        self::assertSame([], $this->mail);
    }

    public function testBootstrapPreservesSponsorTypesAndDailyExpiration(): void
    {
        self::assertTrue(post_type_exists('offering'));
        self::assertTrue(post_type_exists('assignment'));
        $event = wp_get_scheduled_event('sponsor_manager_delete_expired_posts_cron');
        self::assertIsObject($event);
        self::assertSame('daily', $event->schedule);
        self::assertTrue(has_action('sponsor_manager_delete_expired_posts_cron'));
    }
}
