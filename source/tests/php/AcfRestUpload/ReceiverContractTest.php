<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\InvalidRequestException;
use ApiSponsorManager\AcfRestUpload\Receiver;
use ApiSponsorManager\Assignment\PostType as AssignmentPostType;
use ApiSponsorManager\Offering\PostType as OfferingPostType;
use Brain\Monkey\Functions;
use PluginTestCase\PluginTestCase;
use WP_Error;
use WP_REST_Request;

class ReceiverContractTest extends PluginTestCase
{
    private Receiver $receiver;

    private const UUID = '11111111-1111-4111-8111-111111111111';

    public function setUp(): void
    {
        parent::setUp();

        $this->receiver = new Receiver();

        Functions\when('current_user_can')->alias(static fn (): bool => true);
        Functions\when('get_current_user_id')->alias(static fn (): int => 1);

        // No idempotency claim and no upload resolution may ever happen while
        // only preDispatch ran.
        Functions\expect('add_option')->never();
        Functions\expect('media_handle_sideload')->never();
    }

    public function testRequestsWithoutVersionHeaderPassThroughUntouched(): void
    {
        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()], version: null);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => '$file:hero'], $request->get_param('acf'));
    }

    public function testMultipartUpdatesRejectUnsupportedMutableProperties(): void
    {
        foreach (['content', 'excerpt', 'slug', 'author', 'meta', 'featured_media', 'date', 'parent', 'id'] as $name) {
            $request = $this->request([], route: '/wp/v2/sponsor-offerings/44');
            $request->set_body_params(['acf' => [], $name => 99]);
            $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_unsupported_update', 400);
        }
        $json = $this->request([], route: '/wp/v2/sponsor-offerings/44', version: null);
        $json->set_body_params(['content' => 'Native JSON remains unchanged.']);
        self::assertNull($this->receiver->preDispatch(null, null, $json));
    }

    public function testUnsupportedVersionIsAControlledClientError(): void
    {
        $request = $this->request([], version: '2');

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_unsupported_version', 400);
    }

    public function testOnlyPostRequestsAreSupported(): void
    {
        // Native PHP only parses multipart file parts for POST bodies.
        foreach (['PUT', 'PATCH'] as $method) {
            $request = $this->request([], method: $method);

            $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_unsupported_request', 400);
        }
    }

    public function testUnsupportedRoutesAreRejected(): void
    {
        $request = $this->request([], route: '/wp/v2/posts');

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_unsupported_request', 400);

        $garbage = $this->request([], route: '/wp/v2/sponsor-offerings/not-numeric');

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $garbage), 'acf_rest_upload_unsupported_request', 400);
    }

    public function testUploadFilesCapabilityIsRequired(): void
    {
        Functions\when('current_user_can')->alias(static fn (): bool => false);

        $request = $this->request([]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_forbidden', 403);
    }

    public function testIdempotencyKeyIsRequiredAndMustBeAUuid(): void
    {
        $missing = $this->request([], key: null);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $missing), 'acf_rest_upload_missing_idempotency_key', 400);

        $invalid = $this->request([], key: 'not-a-uuid');

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $invalid), 'acf_rest_upload_invalid_idempotency_key', 400);
    }

    public function testReservedFileFieldInBodyIsAConflict(): void
    {
        $request = $this->request([]);
        $request->set_body_params(['_acf_rest_files' => 'not-a-file-part']);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_conflicting_parts', 400);
    }

    public function testFileParamsOutsideTheReservedNamespaceAreRejected(): void
    {
        $request = $this->request([]);
        $request->set_file_params([
            '_acf_rest_files' => [],
            'stray' => ['name' => 'x', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 1],
        ]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_conflicting_parts', 400);
    }

    public function testNestedFilePartsAreRejected(): void
    {
        $request = $this->request([]);
        $request->set_file_params([
            '_acf_rest_files' => [
                'name' => ['hero' => ['deep' => 'nested.jpg']],
                'tmp_name' => ['hero' => ['deep' => '/tmp/nested']],
                'error' => ['hero' => ['deep' => 0]],
                'size' => ['hero' => ['deep' => 5]],
                'type' => ['hero' => ['deep' => 'image/jpeg']],
            ],
        ]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_conflicting_parts', 400);
    }

    public function testNumericFilePartKeysAreAccepted(): void
    {
        $this->stubUploadFieldGroup();

        $request = $this->request(['image' => '$file:0']);
        $request->set_file_params([
            '_acf_rest_files' => [
                'name' => [0 => 'photo.jpg'],
                'tmp_name' => [0 => '/tmp/photo'],
                'error' => [0 => 0],
                'size' => [0 => 5],
                'type' => [0 => 'image/jpeg'],
            ],
        ]);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
    }

    public function testReservedKeysInsideAcfPayloadAreRejected(): void
    {
        $request = $this->request(['_acf_rest_files' => []]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_conflicting_parts', 400);
    }

    public function testNullPathsMustBeRootedAtAcf(): void
    {
        $request = $this->request([], nulls: ['image', 'title[raw]']);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), InvalidRequestException::CODE, 400);
    }

    public function testNullPathsCollidingWithFileReferencesAreRejected(): void
    {
        $this->stubUploadFieldGroup();

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()], nulls: ['acf[image]']);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), InvalidRequestException::CODE, 400);
    }

    public function testPathsListedAsNullAndEmptyAreRejected(): void
    {
        $request = $this->request([], nulls: ['acf[gallery]'], empties: ['acf[gallery]']);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), InvalidRequestException::CODE, 400);
    }

    public function testMarkersAreClearedAndNullsAreAppliedBeforeValidation(): void
    {
        $this->stubUploadFieldGroup();

        $request = $this->request(
            ['image' => '$file:hero'],
            ['hero' => $this->fileRecord()],
            nulls: ['acf[optional][note]']
        );

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null, 'optional' => ['note' => null]], $request->get_param('acf'));
    }

    public function testEmptyListPathsAreAppliedAsEmptyArrays(): void
    {
        $this->stubUploadFieldGroup(withGallery: true);

        $request = $this->request(['gallery' => ''], empties: ['acf[gallery]', 'acf[fresh][]']);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['gallery' => [], 'fresh' => []], $request->get_param('acf'));
    }

    public function testGalleryReferencesWithNumericIndexKeepTheirPosition(): void
    {
        $this->stubUploadFieldGroup(withGallery: true);

        $request = $this->request(
            ['gallery' => [123, '$file:hero']],
            ['hero' => $this->fileRecord()]
        );

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        // The marker became null at its exact index; the existing id stays.
        self::assertSame(['gallery' => [123, null]], $request->get_param('acf'));
    }

    public function testReferencesToFieldsOfOtherPostTypesAreRejected(): void
    {
        // The group is located on the registered assignment type only, while
        // the request targets the offerings route.
        $this->stubUploadFieldGroup(postType: $this->registeredAssignmentType());

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
    }

    /**
     * Regression: the assignments route must resolve fields on the registered
     * `assignment` post type, not on the REST base or a wrong type.
     */
    public function testFieldDiscoveryUsesTheRegisteredAssignmentType(): void
    {
        $this->stubUploadFieldGroup(postType: $this->registeredAssignmentType());

        $request = $this->request(
            ['image' => '$file:hero'],
            ['hero' => $this->fileRecord()],
            route: '/wp/v2/sponsor-assignments'
        );

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
    }

    /**
     * Regression: the offerings route must resolve fields on the registered
     * `offering` post type, not on the REST base or a wrong type.
     */
    public function testFieldDiscoveryUsesTheRegisteredOfferingType(): void
    {
        $this->stubUploadFieldGroup(postType: $this->registeredOfferingType());

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
    }

    /**
     * Regression: the interrupted-claim recovery lookup must query the
     * registered post types, so a saved resource is found on either route.
     */
    public function testRecoveryLookupQueriesTheRegisteredPostTypes(): void
    {
        $capturedArgs = null;

        Functions\when('get_posts')->alias(static function (array $query) use (&$capturedArgs): array {
            $capturedArgs = $query;

            return [];
        });

        $method = new \ReflectionMethod(Receiver::class, 'findPostByIdempotencyKey');
        $method->invoke($this->receiver, self::UUID);

        self::assertIsArray($capturedArgs);
        self::assertEqualsCanonicalizing(
            [$this->registeredAssignmentType(), $this->registeredOfferingType()],
            $capturedArgs['post_type'] ?? null
        );
    }

    public function testAmbiguousFieldNamesAreRejected(): void
    {
        $first = ['key' => 'field_image_1', 'name' => 'image', 'type' => 'image', 'parent' => 'group_1', 'allow_multipart_rest_upload' => 1];
        $second = ['key' => 'field_image_2', 'name' => 'image', 'type' => 'image', 'parent' => 'group_2', 'allow_multipart_rest_upload' => 1];

        Functions\when('acf_get_field_groups')->alias(static fn ($args) => [
            ['key' => 'group_1', 'show_in_rest' => 1],
            ['key' => 'group_2', 'show_in_rest' => 1],
        ]);
        Functions\when('acf_get_fields')->alias(static fn ($group) => $group === 'group_1' ? [$first] : [$second]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        Functions\when('acf_get_field')->alias(static fn ($name) => $first);
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => ['key' => 'group_1', 'show_in_rest' => 1]);

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
    }

    public function testDisabledOrNonUploadFieldsAreRejected(): void
    {
        $plainField = ['key' => 'field_text', 'name' => 'image', 'type' => 'text', 'parent' => 'group_g'];
        $disabledField = ['key' => 'field_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_g', 'allow_multipart_rest_upload' => 0];

        foreach ([$plainField, $disabledField] as $field) {
            $this->stubUploadFieldGroup(fields: [$field]);

            $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

            $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
        }
    }

    /**
     * Regression: a same-named field in a group that is not REST exposed must
     * not block the selection of the eligible field.
     */
    public function testDuplicateNameInGroupHiddenFromRestDoesNotAffectSelection(): void
    {
        $visible = ['key' => 'field_offer_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_visible', 'allow_multipart_rest_upload' => 1];
        $hidden = ['key' => 'field_hidden_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_hidden', 'allow_multipart_rest_upload' => 1];
        $postType = $this->registeredOfferingType();

        Functions\when('acf_get_field_groups')->alias(static fn ($args) => ($args['post_type'] ?? null) === $postType
            ? [
                ['key' => 'group_visible', 'show_in_rest' => 1],
                ['key' => 'group_hidden', 'show_in_rest' => 0],
            ] : []);
        Functions\when('acf_get_fields')->alias(static fn ($group) => $group === 'group_visible' ? [$visible] : [$hidden]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => ['key' => 'group_visible', 'show_in_rest' => 1]);

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
    }

    /**
     * Regression: two eligible fields with the same name must be rejected as
     * a controlled client error, never resolved by silent first-match.
     */
    public function testTwoEligibleFieldsWithSameNameReturnControlledError(): void
    {
        $first = ['key' => 'field_offer_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_a', 'allow_multipart_rest_upload' => 1];
        $second = ['key' => 'field_other_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_b', 'allow_multipart_rest_upload' => 1];
        $postType = $this->registeredOfferingType();

        Functions\when('acf_get_field_groups')->alias(static fn ($args) => ($args['post_type'] ?? null) === $postType
            ? [
                ['key' => 'group_a', 'show_in_rest' => 1],
                ['key' => 'group_b', 'show_in_rest' => 1],
            ] : []);
        Functions\when('acf_get_fields')->alias(static fn ($group) => $group === 'group_a' ? [$first] : [$second]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => ['key' => 'group_a', 'show_in_rest' => 1]);

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
    }

    /**
     * Regression: a field whose type is hidden from the REST API through the
     * field type `show_in_rest` property must be rejected.
     */
    public function testFieldTypeHiddenFromRestRejectsReference(): void
    {
        $this->stubUploadFieldGroup();

        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => false]);

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
    }

    /**
     * Regression: a field removed by the `acf/rest/get_fields` filter must be
     * rejected, and the filter must receive the ACF resource shape.
     */
    public function testFieldRemovedByRestGetFieldsFilterRejectsReference(): void
    {
        $this->stubUploadFieldGroup();

        $capturedResource = null;
        $capturedMethod = null;

        Functions\when('apply_filters')->alias(static function (string $hook, array $fields, array $resource, string $httpMethod) use (&$capturedResource, &$capturedMethod): array {
            $capturedResource = $resource;
            $capturedMethod = $httpMethod;

            return array_values(array_filter($fields, static fn (array $field): bool => ($field['name'] ?? '') !== 'image'));
        });

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), 'acf_rest_upload_invalid_reference', 400);
        self::assertSame(['type' => 'post', 'sub_type' => $this->registeredOfferingType(), 'id' => null], $capturedResource);
        self::assertSame('POST', $capturedMethod);
    }

    /**
     * Regression: database stored fields record the numeric group post id as
     * parent; such a field must resolve through the group id identity.
     */
    public function testDatabaseBackedIntegerFieldParentIsAccepted(): void
    {
        $groupId = 5501;
        $field = ['key' => 'field_db_image', 'name' => 'image', 'type' => 'image', 'parent' => $groupId, 'allow_multipart_rest_upload' => 1];
        $group = ['key' => 'group_db', 'ID' => $groupId, 'show_in_rest' => 1];
        $postType = $this->registeredOfferingType();

        Functions\when('acf_get_field_groups')->alias(static fn ($args) => ($args['post_type'] ?? null) === $postType ? [$group] : []);
        Functions\when('acf_get_fields')->alias(static fn (): array => [$field]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => is_numeric($parent) && (int) $parent === $groupId ? $group : false);

        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()]);

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
    }

    /**
     * Regression: an update request with a numeric target id must resolve
     * fields on the target post resource, and the `acf/rest/get_fields`
     * filter must receive that resource and the actual method.
     */
    public function testUpdateRequestResolvesFieldsByTargetPostId(): void
    {
        $postType = $this->registeredOfferingType();
        $targetId = 4402;
        $field = ['key' => 'field_update_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_update', 'allow_multipart_rest_upload' => 1];
        $capturedArgs = null;
        $capturedResource = null;
        $capturedMethod = null;

        Functions\when('acf_get_field_groups')->alias(static function (array $args) use (&$capturedArgs, $targetId): array {
            $capturedArgs = $args;

            return ($args['post_id'] ?? null) === $targetId ? [['key' => 'group_update', 'show_in_rest' => 1]] : [];
        });
        Functions\when('acf_get_fields')->alias(static fn (): array => [$field]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->alias(static function (string $hook, array $fields, array $resource, string $httpMethod) use (&$capturedResource, &$capturedMethod): array {
            $capturedResource = $resource;
            $capturedMethod = $httpMethod;

            return $fields;
        });
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => $parent === 'group_update' ? ['key' => 'group_update', 'show_in_rest' => 1] : false);

        // The numeric route suffix makes this an update of post 4402, so the
        // file reference validation must resolve fields on that post.
        $request = $this->request(['image' => '$file:hero'], ['hero' => $this->fileRecord()], route: "/wp/v2/sponsor-offerings/{$targetId}");

        self::assertNull($this->receiver->preDispatch(null, null, $request));
        self::assertSame(['image' => null], $request->get_param('acf'));
        self::assertSame(['post_id' => $targetId], $capturedArgs);
        self::assertSame(['type' => 'post', 'sub_type' => $postType, 'id' => $targetId], $capturedResource);
        self::assertSame('POST', $capturedMethod);
    }

    /**
     * Regression: the resolved field must be the eligible field itself with
     * its exact key; the global lookup by name must not be consulted.
     */
    public function testResolvedDestinationFieldKeepsItsExactKey(): void
    {
        $offeringField = ['key' => 'field_offer_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_offer', 'allow_multipart_rest_upload' => 1];
        $postType = $this->registeredOfferingType();

        Functions\when('acf_get_field_groups')->alias(static fn ($args) => ($args['post_type'] ?? null) === $postType
            ? [['key' => 'group_offer', 'show_in_rest' => 1]] : []);
        Functions\when('acf_get_fields')->alias(static fn (): array => [$offeringField]);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        // A global lookup by name would return a different field; the
        // resolution must never perform it.
        Functions\expect('acf_get_field')->never();

        $method = new \ReflectionMethod(Receiver::class, 'resolveDestinationField');
        $resolved = $method->invoke($this->receiver, $this->registeredOfferingType(), null, 'image', 'POST');

        self::assertIsArray($resolved);
        self::assertSame('field_offer_image', $resolved['key'] ?? null);
        self::assertSame('group_offer', $resolved['parent'] ?? null);
    }

    public function testMissingFilePartIsAControlledClientError(): void
    {
        $this->stubUploadFieldGroup();

        // Referenced key without an uploaded part.
        $request = $this->request(['image' => '$file:hero']);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $request), InvalidRequestException::CODE, 400);

        // Uploaded part without any reference.
        $unexpected = $this->request(['image' => 123], ['hero' => $this->fileRecord()]);

        $this->assertProtocolError($this->receiver->preDispatch(null, null, $unexpected), InvalidRequestException::CODE, 400);
    }

    /**
     * @param array<string, mixed> $acf
     * @param array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    private function request(
        array $acf,
        array $files = [],
        ?array $nulls = null,
        ?array $empties = null,
        string $method = 'POST',
        string $route = '/wp/v2/sponsor-offerings',
        ?string $version = '1',
        ?string $key = self::UUID
    ): WP_REST_Request {
        $request = new WP_REST_Request($method, $route);

        if ($version !== null) {
            $request->set_header('X-ACF-Rest-Upload-Version', $version);
        }

        if ($key !== null) {
            $request->set_header('Idempotency-Key', $key);
        }

        $body = [];

        if ($acf !== [] || $nulls !== null || $empties !== null) {
            $body['acf'] = $acf;
        }

        if ($nulls !== null) {
            $body['_acf_rest_nulls'] = $nulls;
        }

        if ($empties !== null) {
            $body['_acf_rest_empty'] = $empties;
        }

        $request->set_body_params($body);
        $request->set_file_params($files === [] ? [] : ['_acf_rest_files' => $files]);

        return $request;
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function fileRecord(): array
    {
        return ['name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/photo', 'error' => UPLOAD_ERR_OK, 'size' => 5];
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function stubUploadFieldGroup(
        bool $withGallery = false,
        ?string $postType = null,
        ?array $fields = null
    ): void {
        $postType ??= $this->registeredOfferingType();
        $primary = ['key' => 'field_primary', 'name' => 'image', 'type' => 'image', 'parent' => 'group_g', 'allow_multipart_rest_upload' => 1];
        $gallery = ['key' => 'field_gallery', 'name' => 'gallery', 'type' => 'gallery', 'parent' => 'group_g', 'allow_multipart_rest_upload' => 1];
        $resolved = $fields ?? ($withGallery ? [$primary, $gallery] : [$primary]);

        Functions\when('acf_get_field_groups')->alias(
            static fn ($args) => ($args['post_type'] ?? null) === $postType ? [['key' => 'group_g', 'show_in_rest' => 1]] : []
        );
        Functions\when('acf_get_fields')->alias(static fn ($group) => $resolved);
        Functions\when('acf_get_field_type')->alias(static fn (string $type): object => (object) ['name' => $type, 'show_in_rest' => true]);
        Functions\when('apply_filters')->returnArg(1);
        Functions\when('acf_get_field')->alias(static fn ($name) => match ($name) {
            'gallery' => $gallery,
            'image' => $primary,
            default => false,
        });
        Functions\when('acf_get_field_group')->alias(static fn ($parent) => ['key' => 'group_g', 'show_in_rest' => 1]);
    }

    /**
     * The registered post type names, read from the registering classes so
     * the regression cases never duplicate the receiver's route map.
     */
    private function registeredAssignmentType(): string
    {
        return (new class extends AssignmentPostType {
            public function __construct()
            {
                // getName() reads no WordPress service; skip the constructor.
            }
        })->getName();
    }

    private function registeredOfferingType(): string
    {
        return (new class extends OfferingPostType {
            public function __construct()
            {
                // getName() reads no WordPress service; skip the constructor.
            }
        })->getName();
    }

    private function assertProtocolError(mixed $result, string $code, int $status): void
    {
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        self::assertSame($status, $result->get_error_data($code)['status'] ?? null);
    }
}
