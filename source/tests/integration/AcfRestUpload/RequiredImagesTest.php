<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use ApiSponsorManager\AcfRestUpload\Recovery;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;
use WpService\Implementations\NativeWpService;

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

    protected function request(): WP_REST_Request
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

    protected function dispatch(WP_REST_Request $request): WP_REST_Response
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

    private function cleanup(): void
    {
        (new Recovery(new NativeWpService()))->cleanup();
    }

    /** @return list<int> */
    private function markedAttachments(): array
    {
        return array_values(array_filter(
            $this->attachments,
            static fn (int $id): bool => get_post_meta($id, Recovery::CLEANUP, true) === '1'
        ));
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
            // A successful native create leaves no cleanup marker.
            self::assertSame('', get_post_meta($attachment, Recovery::CLEANUP, true));
        }
        self::assertCount(1, $this->mail);
        self::assertSame('Saved 7', $this->mail[0]['subject']);
        self::assertSame(['permission', 'move', 'attachment', 'move', 'attachment', 'validate:image_a', 'validate:image_b',
            'permission', 'insert', 'saved', 'mail'], $this->events);
    }

    public function testSecondUploadFailureMarksTheFirstAttachmentAndDoesNotNotify(): void
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
        self::assertNotNull(get_post($this->attachments[0]));
        self::assertSame('1', get_post_meta($this->attachments[0], Recovery::CLEANUP, true));
        self::assertCount(1, $paths);
        self::assertFileIsReadable($paths[0]);
        self::assertSame([], get_posts(['post_type' => 'image_proof', 'post_status' => 'any']));
        self::assertSame([], $this->mail);
    }

    public function testMetadataGenerationFaultMarksTheCapturedAttachment(): void
    {
        $fault = static fn () => throw new \RuntimeException('Private metadata detail');
        add_filter('wp_generate_attachment_metadata', $fault, PHP_INT_MAX);
        try { $response = $this->dispatch($this->request()); }
        finally { remove_filter('wp_generate_attachment_metadata', $fault, PHP_INT_MAX); }
        self::assertSame(500, $response->get_status());
        self::assertSame('acf_rest_upload_storage_failed', $response->get_data()['code']);
        self::assertCount(1, $this->attachments);
        self::assertNotNull(get_post($this->attachments[0]));
        self::assertSame('1', get_post_meta($this->attachments[0], Recovery::CLEANUP, true));
        self::assertFileIsReadable(get_attached_file($this->attachments[0]));
        self::assertSame([], $this->mail);
        // The failed preparation released its context and hook.
        self::assertSame(201, $this->dispatch($this->request())->get_status());
    }

    public function testUnrelatedSideloadsAndExistingImagesAreNeverMarked(): void
    {
        $existing = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $this->attachments = [];
        $unrelated = null;
        $inNested = false;
        add_filter('wp_handle_sideload_prefilter', function (array $file) use (&$unrelated, &$inNested): array {
            if ($inNested) { return $file; }
            if ($unrelated === null) {
                // Sideload a copy so the unrelated upload does not consume this request's temporary file.
                $copy = wp_tempnam('unrelated.jpg');
                copy($file['tmp_name'], $copy);
                $inNested = true;
                try {
                    $unrelated = media_handle_sideload(['name' => 'unrelated.jpg', 'type' => 'image/jpeg',
                        'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)], 0);
                } finally { $inNested = false; }
                return $file;
            }
            $file['error'] = 'Controlled second upload failure';
            return $file;
        });
        $response = $this->dispatch($this->request());
        self::assertSame(500, $response->get_status());
        self::assertIsInt($unrelated);
        self::assertCount(2, $this->attachments);
        self::assertSame([$this->attachments[1]], $this->markedAttachments());
        self::assertNotSame($unrelated, $this->attachments[1]);
        self::assertSame('', get_post_meta($unrelated, Recovery::CLEANUP, true));
        self::assertSame('', get_post_meta($existing, Recovery::CLEANUP, true));
        self::assertNotNull(get_post($unrelated));
        self::assertNotNull(get_post($existing));
        wp_delete_attachment($existing, true);
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

    public function testNativeValidationFailureAfterHandoffLeavesUploadsWithoutAMarker(): void
    {
        $request = $this->request();
        $payload = json_decode($request->get_body_params()['_acf_rest_payload'], true);
        $payload['acf']['quantity'] = 'not a number';
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode($payload)]);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code']);
        self::assertStringContainsString('quantity', wp_json_encode($response->get_data()));
        // A native failure after handoff is not a receiver cleanup signal.
        self::assertCount(2, $this->attachments);
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame('', get_post_meta($id, Recovery::CLEANUP, true));
        }
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
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame('', get_post_meta($id, Recovery::CLEANUP, true));
        }
        self::assertSame([], $this->mail);
    }

    public function testNativeImageRejectionLeavesUploadsWithoutAMarker(): void
    {
        add_filter('acf/validate_rest_value/type=image', static function ($valid, $value, $field) {
            return $field['key'] === 'field_proof_b' ? new \WP_Error('native_image_rejected', 'Native rejection') : $valid;
        }, PHP_INT_MAX, 3);
        $response = $this->dispatch($this->request());
        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('native_image_rejected', wp_json_encode($response->get_data()));
        self::assertCount(2, $this->attachments);
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame('', get_post_meta($id, Recovery::CLEANUP, true));
        }
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

    public function testSavedFieldMismatchPersistsWithNativeCompletion(): void
    {
        $postId = null;
        $paths = [];
        add_action('rest_after_insert_image_proof', function ($post) use (&$postId, &$paths): void {
            $postId = $post->ID;
            foreach ($this->attachments as $id) { $paths[] = get_attached_file($id); }
            update_post_meta($postId, 'image_b', 0);
        });
        $response = $this->dispatch($this->request());
        // The native response is authoritative; the receiver no longer verifies saved fields.
        self::assertSame(201, $response->get_status());
        self::assertIsInt($postId);
        self::assertNotNull(get_post($postId));
        self::assertCount(2, $this->attachments);
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame('', get_post_meta($id, Recovery::CLEANUP, true));
        }
        foreach ($paths as $path) { self::assertFileIsReadable($path); }
        self::assertCount(1, $this->mail);
    }

    public function testNativeParentingVetoIsLeftToNativeCompletion(): void
    {
        add_filter('acf/connect_attachment_to_post', static fn () => false);
        $response = $this->dispatch($this->request());
        self::assertSame(201, $response->get_status());
        self::assertCount(2, $this->attachments);
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame(0, (int) get_post($id)->post_parent);
            self::assertSame('', get_post_meta($id, Recovery::CLEANUP, true));
        }
        self::assertCount(1, $this->mail);
    }

    public function testBootstrapPreservesSponsorTypesAndDailyExpiration(): void
    {
        self::assertTrue(post_type_exists('offering'));
        self::assertTrue(post_type_exists('assignment'));
        $event = wp_get_scheduled_event('sponsor_manager_delete_expired_posts_cron');
        self::assertIsObject($event);
        self::assertSame('daily', $event->schedule);
        self::assertTrue(has_action('sponsor_manager_delete_expired_posts_cron'));
        $cleanup = wp_get_scheduled_event(Recovery::HOOK);
        self::assertIsObject($cleanup);
        self::assertSame('hourly', $cleanup->schedule);
        self::assertTrue(has_action(Recovery::HOOK));
        do_action('init');
        $events = array_filter(_get_cron_array(), static fn ($events) => isset($events[Recovery::HOOK]));
        self::assertCount(1, $events, 'Repeated init must not duplicate the event.');
        // Event execution belongs to the separately approved fresh-process acceptance fixture.
    }

    #[DataProvider('storageFaults')]
    public function testControlledStorageFaultsMarkCapturedAttachmentsAndReleaseContext(string $hook): void
    {
        $fault = static fn () => throw new \RuntimeException('Private storage detail');
        add_filter($hook, $fault, PHP_INT_MAX);
        try { $response = $this->dispatch($this->request()); }
        finally { remove_filter($hook, $fault, PHP_INT_MAX); }
        self::assertSame(500, $response->get_status());
        self::assertSame('acf_rest_upload_storage_failed', $response->get_data()['code']);
        // Whatever native storage reached an attachment row is marked; nothing is deleted in the request.
        foreach ($this->attachments as $id) {
            self::assertNotNull(get_post($id));
            self::assertSame('1', get_post_meta($id, Recovery::CLEANUP, true));
            self::assertFileIsReadable(get_attached_file($id));
        }
        self::assertSame([], $this->mail);
        self::assertSame(201, $this->dispatch($this->request())->get_status(), 'A controlled exit must release its context.');
    }

    public static function storageFaults(): array
    {
        return [['wp_insert_attachment_data'], ['wp_generate_attachment_metadata']];
    }

    public function testCronCleanupDeletesMarkedUnparentedAttachments(): void
    {
        $attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $other = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($attachment, Recovery::CLEANUP, '1');
        $this->attachments = [];
        $this->cleanup();
        self::assertNull(get_post($attachment));
        self::assertSame([], $this->markedAttachments());
        self::assertNotNull(get_post($other));
        wp_delete_attachment($other, true);
    }

    public function testCronCleanupKeepsTheMarkerAfterANativeDeletionRefusal(): void
    {
        $attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($attachment, Recovery::CLEANUP, '1');
        $this->attachments = [];
        $refuse = static fn () => false;
        add_filter('pre_delete_attachment', $refuse, 10, 2);
        try { $this->cleanup(); }
        finally { remove_filter('pre_delete_attachment', $refuse, 10); }
        self::assertNotNull(get_post($attachment));
        self::assertSame('1', get_post_meta($attachment, Recovery::CLEANUP, true), 'The marker stays for a later run.');
        // The next hourly run finishes the deletion.
        $this->cleanup();
        self::assertNull(get_post($attachment));
    }

    public function testCronCleanupSkipsChangedOwnership(): void
    {
        $parented = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($parented, Recovery::CLEANUP, '1');
        wp_update_post(['ID' => $parented, 'post_parent' => self::factory()->post->create()]);
        // Adoption by a post and a removed marker both count as changed ownership.
        $reowned = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($reowned, Recovery::CLEANUP, '1');
        delete_post_meta($reowned, Recovery::CLEANUP);
        $this->attachments = [];
        $this->cleanup();
        self::assertNotNull(get_post($parented));
        self::assertSame('1', get_post_meta($parented, Recovery::CLEANUP, true));
        self::assertNotNull(get_post($reowned));
        wp_delete_attachment($parented, true);
        wp_delete_attachment($reowned, true);
    }

    public function testCronCleanupFinishesWhenTheAttachmentIsAlreadyDeleted(): void
    {
        $attachment = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        update_post_meta($attachment, Recovery::CLEANUP, '1');
        wp_delete_attachment($attachment, true);
        $this->attachments = [];
        $this->cleanup();
        self::assertNull(get_post($attachment));
        self::assertSame([], get_posts(['post_type' => 'attachment', 'fields' => 'ids',
            'meta_key' => Recovery::CLEANUP, 'meta_value' => '1']));
    }

    public function testNativeScaledMainFilesStayPersisted(): void
    {
        add_filter('big_image_size_threshold', static fn () => 64);
        $response = $this->dispatch($this->request());
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        foreach ($this->attachments as $id) {
            $file = get_attached_file($id);
            self::assertFileIsReadable($file);
            $size = wp_getimagesize($file);
            self::assertLessThanOrEqual(64, max($size[0], $size[1]));
            self::assertSame($response->get_data()['id'], (int) get_post($id)->post_parent);
        }
    }

    public function testMailExceptionDoesNotFailTheNativeCreate(): void
    {
        $fault = static fn () => throw new \RuntimeException('Private mail transport detail');
        add_filter('pre_wp_mail', $fault, PHP_INT_MAX);
        try { $response = $this->dispatch($this->request()); }
        finally { remove_filter('pre_wp_mail', $fault, PHP_INT_MAX); }
        self::assertSame(201, $response->get_status());
        self::assertNotNull(get_post($response->get_data()['id']));
        foreach ($this->attachments as $id) { self::assertFileIsReadable(get_attached_file($id)); }
    }

    #[DataProvider('preflightDenials')]
    public function testPreflightDenialCreatesNoAttachmentsOrMarkers(string $mode): void
    {
        $request = $this->request();
        if ($mode === 'malformed') { $request->set_body_params(['_acf_rest_payload' => '{']); }
        if ($mode === 'permission') { wp_set_current_user(0); }
        if ($mode === 'upload') { add_filter('user_has_cap', static function ($caps) { $caps['upload_files'] = false; return $caps; }); }
        self::assertGreaterThanOrEqual(400, $this->dispatch($request)->get_status());
        self::assertSame([], $this->attachments);
    }

    public static function preflightDenials(): array
    {
        return [['malformed'], ['permission'], ['upload']];
    }
}
