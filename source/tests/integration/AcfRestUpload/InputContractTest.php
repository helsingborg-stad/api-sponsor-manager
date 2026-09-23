<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\Test\NativeTestCase;
use ApiSponsorManager\AcfRestUpload\Recovery;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Posts_Controller;
use WP_REST_Request;

class CompatibleInputController extends WP_REST_Posts_Controller {}

/** Native dispatch is the contract; fixture values do not come from receiver helpers. */
class InputContractTest extends NativeTestCase
{
    private array $temporary = [];
    private array $attachments = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_post_type('input_contract', ['public' => true, 'show_in_rest' => true,
            'rest_controller_class' => CompatibleInputController::class, 'supports' => ['title', 'editor', 'author']]);
        acf_add_local_field_group(['key' => 'group_input', 'title' => 'Input', 'show_in_rest' => 1,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'input_contract']]],
            'fields' => [
                ['key' => 'field_input_a', 'name' => 'image_a', 'type' => 'image'],
                ['key' => 'field_input_b', 'name' => 'image_b', 'type' => 'image'],
            ]]);
        add_action('add_attachment', function ($id): void { $this->attachments[] = $id; });
    }

    public function tear_down(): void
    {
        foreach ($this->attachments as $id) { wp_delete_attachment($id, true); }
        foreach ($this->temporary as $path) { if (is_file($path)) { unlink($path); } }
        acf_remove_local_field_group('group_input');
        foreach (['field_input_a', 'field_input_b'] as $key) { acf_remove_local_field($key); }
        acf_get_store('fields')->reset();
        unset($GLOBALS['wp_rest_additional_fields']['input_contract']);
        unregister_post_type('input_contract');
        parent::tear_down();
    }

    private function request(array $values = ['title' => 'JSON title']): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/input_contract');
        $request->set_header('X-ACF-Rest-Upload-Version', '3');
        $request->set_header('Content-Type', 'multipart/form-data; boundary=input');
        $request->set_body_params(['_acf_rest_payload' => wp_json_encode((object) $values)]);
        return $request;
    }

    private function dispatch(WP_REST_Request $request): \WP_REST_Response
    {
        $_SERVER['REQUEST_METHOD'] = $request->get_method();
        $GLOBALS['wp']->query_vars['rest_route'] = $request->get_route();
        foreach (array_keys($GLOBALS['wp_rest_additional_fields'] ?? []) as $type) {
            unset($GLOBALS['wp_rest_additional_fields'][$type]['acf']);
        }
        acf_get_instance('ACF_Rest_Api')->initialize(null, null, $request);
        $GLOBALS['wp_rest_server'] = null;
        return rest_get_server()->dispatch($request);
    }

    private function images(WP_REST_Request $request, array $sizes = ['first' => null]): void
    {
        $parts = [];
        foreach ($sizes as $key => $size) {
            $path = wp_tempnam('input.jpg');
            copy(DIR_TESTDATA . '/images/canola.jpg', $path);
            $this->temporary[] = $path;
            if ($size !== null) {
                $handle = fopen($path, 'a');
                ftruncate($handle, $size);
                fclose($handle);
            }
            foreach (['name' => 'input.jpg', 'type' => 'client/lie', 'tmp_name' => $path,
                'size' => 0, 'error' => UPLOAD_ERR_OK, 'full_path' => '../../ignored/input.jpg'] as $attribute => $value) {
                $parts[$attribute][$key] = $value;
            }
        }
        $request->set_file_params(['_acf_rest_files' => $parts]);
    }

    #[DataProvider('unsupportedRequests')]
    public function testUnsupportedRequestsCreateNothing(string $version, string $method, string $route, string $code, string $contentType = 'multipart/form-data'): void
    {
        $request = $this->request();
        $request->set_header('X-ACF-Rest-Upload-Version', $version);
        $request->set_header('Content-Type', $contentType);
        $request->set_method($method);
        $request->set_route($route);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('acf_rest_upload_' . $code, $response->get_data()['code']);
        self::assertSame([], get_posts(['post_type' => 'input_contract', 'post_status' => 'any']));
        self::assertSame([], $this->attachments);
    }

    public static function unsupportedRequests(): array
    {
        return [
            'empty header is marked' => ['', 'POST', '/wp/v2/input_contract', 'unsupported_version'],
            'v2 globally' => ['2', 'GET', '/not-registered', 'unsupported_version'],
            'get' => ['3', 'GET', '/wp/v2/input_contract', 'unsupported_request'],
            'update' => ['3', 'POST', '/wp/v2/input_contract/123', 'unsupported_request'],
            'unknown' => ['3', 'POST', '/not-registered', 'unsupported_request'],
            'users' => ['3', 'POST', '/wp/v2/users', 'unsupported_request'],
            'json transport' => ['3', 'POST', '/wp/v2/input_contract', 'unsupported_request', 'application/json'],
            'delete' => ['3', 'DELETE', '/wp/v2/input_contract', 'unsupported_request'],
        ];
    }

    public function testCompatibleNativeSubclassCreatesWithoutDestinationConfiguration(): void
    {
        $response = $this->dispatch($this->request());
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame('JSON title', get_post($response->get_data()['id'])->post_title);
    }

    public function testCustomCallbackCannotMasqueradeAsNativeCreate(): void
    {
        add_filter('rest_endpoints', static function ($routes) {
            foreach ($routes['/wp/v2/input_contract'] as &$handler) {
                if (($handler['callback'][1] ?? null) === 'create_item') {
                    $native = $handler['callback'];
                    $handler['callback'] = static fn ($request) => $native($request);
                }
            }
            return $routes;
        });
        $response = $this->dispatch($this->request());
        self::assertSame('acf_rest_upload_unsupported_request', $response->get_data()['code']);
        self::assertSame([], $this->attachments);
    }

    public function testNativeCallbackOnAnItemRouteIsNotACollectionCreate(): void
    {
        add_filter('rest_endpoints', static function ($routes) {
            $routes['/wp/v2/input_contract/(?P<id>[\\d]+)'] = $routes['/wp/v2/input_contract'];
            return $routes;
        });
        $request = $this->request();
        $request->set_route('/wp/v2/input_contract/123');
        $response = $this->dispatch($request);
        self::assertSame('acf_rest_upload_unsupported_request', $response->get_data()['code']);
        self::assertSame([], get_posts(['post_type' => 'input_contract', 'post_status' => 'any']));
        self::assertSame([], $this->attachments);
    }

    public function testJsonAloneSuppliesWritesWhileNativeControlsRemainEffective(): void
    {
        $request = $this->request();
        $request->set_query_params(['title' => 'Query title', 'content' => 'Query supplement', 'context' => 'edit',
            'acf' => ['image_a' => 'invalid competing value']]);
        $request->set_body_params($request->get_body_params() + ['title' => 'Multipart title',
            'content' => 'Multipart supplement', 'acf' => ['image_a' => 'invalid competing value'], '_fields' => 'id,title']);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status());
        self::assertSame('JSON title', $response->get_data()['title']['raw']);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        self::assertSame(['id', 'title'], array_keys($response->get_data()));
        $post = get_post($response->get_data()['id']);
        self::assertSame('JSON title', $post->post_title);
        self::assertSame('', $post->post_content);
        self::assertSame('', get_post_meta($post->ID, 'image_a', true));
    }

    public function testPayloadDecoderPreservesOrdinaryJsonTypesAndScansObjects(): void
    {
        $request = $this->request(['ordinary' => (object) ['empty' => new \stdClass(),
            'list' => [null, false, 0, 'text', (object) ['nested' => 7]]]]);
        $decoded = (new \ApiSponsorManager\AcfRestUpload\CreatePayload())->decode($request);
        self::assertIsObject($decoded['values']['ordinary']);
        self::assertSame('{"empty":{},"list":[null,false,0,"text",{"nested":7}]}',
            wp_json_encode($decoded['values']['ordinary']));
        $request = $this->request(['ordinary' => (object) ['nested' => '$file:hidden']]);
        $response = $this->dispatch($request);
        self::assertSame('acf_rest_upload_invalid_reference', $response->get_data()['code']);
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejectedBeforeResources(mixed $json, string $code, int $status): void
    {
        $request = $this->request();
        $request->set_body_params(['_acf_rest_payload' => $json]);
        $response = $this->dispatch($request);
        self::assertSame($status, $response->get_status());
        self::assertSame('acf_rest_upload_' . $code, $response->get_data()['code']);
        self::assertSame([], $this->attachments);
    }

    public static function invalidPayloads(): array
    {
        $cases = [];
        foreach ([null, ['{}', '{}'], '', '{', '[]', 'null', 'false', '3', '"text"'] as $json) {
            $cases[] = [$json, 'invalid_payload', 400];
        }
        $cases[] = ['{"title":"' . str_repeat('a', 1_048_576 - 11) . '"}', 'too_large', 413];
        return $cases;
    }

    public function testExactJsonByteLimitIsAccepted(): void
    {
        $request = $this->request();
        $json = str_repeat(' ', 1_048_576 - 20) . '{"title":"Boundary"}';
        self::assertSame(1_048_576, strlen($json));
        $request->set_body_params(['_acf_rest_payload' => $json]);
        self::assertSame(201, $this->dispatch($request)->get_status());
    }

    #[DataProvider('referenceKeys')]
    public function testSharedKeyCreatesOneAttachmentAndChecksBothSavedFields(string $key): void
    {
        $request = $this->request(['title' => 'Shared', 'acf' => ['image_a' => '$file:' . $key, 'image_b' => '$file:' . $key]]);
        $this->images($request, [$key => null]);
        $response = $this->dispatch($request);
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertCount(1, $this->attachments);
        foreach (['image_a', 'image_b'] as $name) {
            self::assertSame($this->attachments[0], (int) get_post_meta($response->get_data()['id'], $name, true));
        }
        self::assertSame($response->get_data()['id'], (int) get_post($this->attachments[0])->post_parent);
        self::assertSame(hash_file('sha256', DIR_TESTDATA . '/images/canola.jpg'),
            hash_file('sha256', get_attached_file($this->attachments[0])));
    }

    public static function referenceKeys(): array { return [['first'], ['0'], ['opaque.key'], ['../not-a-path']]; }

    #[DataProvider('existingImages')]
    public function testExistingImagesAreNeverUploadedOrMarked(bool $parented, bool $fail): void
    {
        $parent = $parented ? self::factory()->post->create() : 0;
        $image = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg', $parent);
        $path = get_attached_file($image);
        $this->attachments = [];
        add_filter('user_has_cap', static function ($caps) { $caps['upload_files'] = false; return $caps; });
        if ($fail) {
            add_filter('rest_request_after_callbacks', static fn () => new \WP_Error('native_rejection', 'Rejected', ['status' => 400]), 5);
        }
        $request = $this->request(['title' => 'Existing', 'acf' => ['image_a' => $image]]);
        $response = $this->dispatch($request);
        self::assertSame($fail ? 400 : 201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertFileIsReadable($path);
        self::assertSame([], $this->attachments);
        self::assertSame('', get_post_meta($image, Recovery::CLEANUP, true));
        if ($parented) {
            self::assertSame($parent, (int) get_post($image)->post_parent);
        } else {
            // Native ACF owns the connect decision for an unparented existing image now.
            self::assertGreaterThan(0, (int) get_post($image)->post_parent);
        }
        if (!$fail) {
            self::assertSame($image, (int) get_post_meta($response->get_data()['id'], 'image_a', true));
        } else {
            // A native rejection after insertion is not a receiver cleanup signal.
            self::assertNotSame([], get_posts(['post_type' => 'input_contract', 'post_status' => 'any']));
        }
        wp_delete_attachment($image, true);
    }

    public static function existingImages(): array { return [[false, false], [true, false], [false, true], [true, true]]; }

    public function testOptionalImagesNeedNoUploadPermission(): void
    {
        add_filter('user_has_cap', static function ($caps) { $caps['upload_files'] = false; return $caps; });
        $response = $this->dispatch($this->request(['title' => 'No upload', 'acf' => ['image_a' => null]]));
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame([], $this->attachments);
    }

    #[DataProvider('incompleteCreates')]
    public function testZeroImagePostAndSharedReferenceUseTheNativeResult(bool $shared): void
    {
        $request = $this->request($shared ? ['title' => 'Shared reference', 'acf' => ['image_a' => '$file:first', 'image_b' => '$file:first']]
            : ['title' => 'Missing zero-image post']);
        if ($shared) { $this->images($request); }
        $response = $this->dispatch($request);
        // The native result is authoritative; a 201 no longer needs receiver verification.
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertNotNull(get_post($response->get_data()['id']));
        if ($shared) {
            self::assertCount(1, $this->attachments);
            self::assertNotNull(get_post($this->attachments[0]));
            self::assertSame('', get_post_meta($this->attachments[0], Recovery::CLEANUP, true));
            self::assertSame($this->attachments[0], (int) get_post_meta($response->get_data()['id'], 'image_b', true));
        } else {
            self::assertSame([], $this->attachments);
        }
    }

    public static function incompleteCreates(): array { return [[false], [true]]; }

    #[DataProvider('forbiddenReferences')]
    public function testReservedReferencesMustIdentifyUniqueExposedImages(array $values, array $field, bool $hidden, bool $ambiguous): void
    {
        if ($field !== []) {
            acf_add_local_field($field + ['key' => 'field_input_a', 'name' => 'image_a', 'parent' => 'group_input']);
        }
        acf_get_store('fields')->reset();
        if ($hidden) {
            add_filter('acf/rest/get_fields', static fn ($fields) => array_filter($fields, static fn ($f) => $f['key'] !== 'field_input_a'));
        }
        if ($ambiguous) {
            acf_add_local_field(['key' => 'field_input_duplicate', 'name' => 'image_a', 'type' => 'image', 'parent' => 'group_input']);
        }
        $request = $this->request($values);
        $this->images($request);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('acf_rest_upload_invalid_reference', $response->get_data()['code']);
        self::assertSame([], $this->attachments);
        acf_remove_local_field('field_input_duplicate');
    }

    public static function forbiddenReferences(): array
    {
        $cases = [];
        foreach (['content' => '$file:first', 'empty' => '', 'nested' => ['child' => '$file:first']] as $name => $value) {
            $values = $name === 'content' ? ['content' => $value] : ['acf' => ['image_a' => $name === 'empty' ? '$file:' : $value]];
            $cases[$name] = [$values, [], false, false];
        }
        $values = ['acf' => ['image_a' => '$file:first']];
        foreach (['gallery', 'repeater', 'file'] as $type) { $cases[$type] = [$values, ['type' => $type], false, false]; }
        $cases['hidden'] = [$values, [], true, false];
        $cases['ambiguous'] = [$values, [], false, true];
        $cases['numeric unknown field'] = [['acf' => ['0' => '$file:first']], [], false, false];
        return $cases;
    }

    #[DataProvider('malformedParts')]
    public function testMalformedPartsFailBeforeAttributesAreUsed(string $mutation): void
    {
        $request = $this->request(['acf' => ['image_a' => '$file:first']]);
        $this->images($request);
        $parts = $request->get_file_params();
        switch ($mutation) {
            case 'missing': $parts = []; break;
            case 'unexpected': $parts['other'] = $parts['_acf_rest_files']; break;
            case 'zero-image': $request = $this->request(); break;
            case 'key-mismatch': $parts['_acf_rest_files']['name'] = ['other' => 'input.jpg']; break;
            case 'missing-attribute': unset($parts['_acf_rest_files']['error']); break;
            case 'nested': $parts['_acf_rest_files']['tmp_name']['first'] = ['path']; break;
            case 'error-type': $parts['_acf_rest_files']['error']['first'] = '0'; break;
            case 'size-type': $parts['_acf_rest_files']['size']['first'] = []; break;
            case 'scalar': $parts['_acf_rest_files'] = 'not an upload'; break;
        }
        $request->set_file_params($parts);
        $response = $this->dispatch($request);
        self::assertSame(400, $response->get_status());
        self::assertSame('acf_rest_upload_invalid_parts', $response->get_data()['code']);
        self::assertSame([], $this->attachments);
    }

    public static function malformedParts(): array
    {
        return array_map(static fn ($case) => [$case], ['missing', 'unexpected', 'zero-image', 'key-mismatch',
            'missing-attribute', 'nested', 'error-type', 'size-type', 'scalar']);
    }

    #[DataProvider('imageLimits')]
    public function testMeasuredBytesRespectEveryFieldAndAggregateBoundary(array $sizes, array $limits, int $status): void
    {
        $keys = array_keys($sizes);
        $request = $this->request(['title' => 'Limits', 'acf' => [
            'image_a' => '$file:' . $keys[0], 'image_b' => '$file:' . ($keys[1] ?? $keys[0])]]);
        $this->images($request, $sizes);
        add_filter('acf/load_field/key=field_input_b', static fn ($field) => array_replace($field, $limits));
        $response = $this->dispatch($request);
        self::assertSame($status, $response->get_status(), wp_json_encode($response->get_data()));
        if ($status === 201) {
            self::assertCount(count($sizes), $this->attachments);
            foreach ($this->attachments as $index => $id) {
                self::assertSame(array_values($sizes)[$index], filesize(get_attached_file($id)));
            }
        } else {
            self::assertSame('acf_rest_upload_' . ($status === 413 ? 'too_large' : 'invalid_file'), $response->get_data()['code']);
            self::assertSame([], $this->attachments, 'All images must pass before any storage.');
        }
    }

    public static function imageLimits(): array
    {
        return [
            'aggregate exact' => [['a' => 4_194_304, 'b' => 4_194_304], [], 201],
            'aggregate plus one' => [['a' => 4_194_304, 'b' => 4_194_305], [], 413],
            'shared counted once' => [['a' => 8_388_608], [], 201],
            'single plus one' => [['a' => 8_388_609], [], 413],
            'field max exact' => [['a' => 1_048_576], ['max_size' => 1], 201],
            'field max plus one' => [['a' => 1_048_577], ['max_size' => 1], 413],
            'field min exact' => [['a' => 1_048_576], ['min_size' => 1], 201],
            'field min minus one' => [['a' => 1_048_575], ['min_size' => 1], 400],
            'shared stricter width' => [['a' => 1_048_576], ['max_width' => 1], 413],
            'shared minimum height' => [['a' => 1_048_576], ['min_height' => 10000], 400],
            'dimensions exact' => [['a' => 1_048_576], ['min_width' => 640, 'max_width' => 640, 'min_height' => 480, 'max_height' => 480], 201],
            'width below minimum' => [['a' => 1_048_576], ['min_width' => 641], 400],
            'height above maximum' => [['a' => 1_048_576], ['max_height' => 479], 413],
        ];
    }

    #[DataProvider('invalidImages')]
    public function testImageFailuresAreControlledBeforeStorage(string $case, string $code, int $status): void
    {
        $request = $this->request(['acf' => ['image_a' => '$file:first']]);
        $this->images($request);
        $parts = $request->get_file_params();
        $path = $parts['_acf_rest_files']['tmp_name']['first'];
        switch ($case) {
            case 'empty': file_put_contents($path, ''); break;
            case 'text': file_put_contents($path, 'not image bytes'); break;
            case 'svg': file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"/>'); break;
            case 'dimensions': file_put_contents($path, "\xFF\xD8\xFF\xE0" . str_repeat("\0", 16)); break;
            case 'missing': unlink($path); break;
            case 'partial': $parts['_acf_rest_files']['error']['first'] = UPLOAD_ERR_PARTIAL; break;
            case 'ini': $parts['_acf_rest_files']['error']['first'] = UPLOAD_ERR_INI_SIZE; break;
            case 'field-type': add_filter('acf/load_field/key=field_input_a', static fn ($field) => array_replace($field, ['mime_types' => 'png'])); break;
            case 'site-type': add_filter('upload_mimes', static fn () => ['png' => 'image/png']); break;
            case 'platform': add_filter('upload_size_limit', static fn () => 10); break;
        }
        $request->set_file_params($parts);
        $response = $this->dispatch($request);
        self::assertSame($status, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame('acf_rest_upload_' . $code, $response->get_data()['code']);
        self::assertStringNotContainsString($path, wp_json_encode($response->get_data()));
        self::assertSame([], $this->attachments);
    }

    public static function invalidImages(): array
    {
        return [['empty', 'invalid_file', 400], ['text', 'invalid_file_type', 415], ['svg', 'invalid_file_type', 415],
            ['missing', 'invalid_file', 400], ['partial', 'invalid_file', 400], ['ini', 'too_large', 413],
            ['field-type', 'invalid_file_type', 415], ['site-type', 'invalid_file_type', 415], ['platform', 'too_large', 413],
            ['dimensions', 'invalid_file_type', 415]];
    }

    public function testNestedValuesReachNativeValidationAndSaving(): void
    {
        register_rest_field('input_contract', 'ordinary', ['schema' => ['type' => 'object', 'properties' => [
            'list' => ['type' => 'array', 'items' => ['type' => ['integer', 'boolean', 'string', 'null']]],
            'object' => ['type' => 'object'], 'empty' => ['type' => 'object']]],
            'update_callback' => static function ($value, $post) { update_post_meta($post->ID, 'ordinary', $value); }]);
        $response = $this->dispatch($this->request(['title' => 'Types', 'ordinary' => (object) [
            'list' => [0, false, 'text', null], 'object' => (object) ['nested' => ['value' => 7]], 'empty' => new \stdClass()]]));
        self::assertSame(201, $response->get_status(), wp_json_encode($response->get_data()));
        self::assertSame('{"list":[0,false,"text",null],"object":{"nested":{"value":7}},"empty":[]}',
            wp_json_encode(get_post_meta($response->get_data()['id'], 'ordinary', true)));
    }

    public function testAnonymousCreateRetainsNativeDenialAndAuthenticationHeaders(): void
    {
        wp_set_current_user(0);
        $request = $this->request();
        $request->set_header('X-WP-Nonce', 'synthetic-invalid-nonce');
        $response = $this->dispatch($request);
        self::assertSame(401, $response->get_status());
        self::assertSame('rest_cannot_create', $response->get_data()['code']);
        self::assertSame('synthetic-invalid-nonce', $request->get_header('X-WP-Nonce'));
        self::assertSame([], $this->attachments);
    }
}
