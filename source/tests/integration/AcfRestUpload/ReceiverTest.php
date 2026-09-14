<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\Receiver;
use ApiSponsorManager\Assignment\PostType as AssignmentPostType;
use ApiSponsorManager\Offering\PostType as OfferingPostType;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Requires the WordPress core test bootstrap, ACF PRO and this plugin's autoloader.
 * Kept separate from the standalone PHPUnit suite because it creates real media.
 *
 * Run with: vendor/bin/phpunit --testsuite integration
 */
class ReceiverTest extends WP_UnitTestCase
{
    private Receiver $receiver;

    private bool $allowed = true;

    private bool $saved = false;

    private int $saveCount = 0;

    private bool $rejectAttachment = false;

    private array $validated = [];

    /** @var list<int> */
    private array $notifications = [];

    /** @var list<string> */
    private array $tempFiles = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        acf_add_local_field_group([
            'key' => 'group_upload_regression',
            'title' => 'Upload regression',
            'show_in_rest' => 1,
            'location' => [
                [
                    ['param' => 'post_type', 'operator' => '==', 'value' => $this->registeredOfferingType()],
                ],
                [
                    ['param' => 'post_type', 'operator' => '==', 'value' => $this->registeredAssignmentType()],
                ],
            ],
            'fields' => [
                [
                    'key' => 'field_upload_regression_image',
                    'name' => 'image',
                    'type' => 'image',
                    'required' => 1,
                    'allow_multipart_rest_upload' => 1,
                ],
                [
                    'key' => 'field_upload_regression_optional_image',
                    'name' => 'optional_image',
                    'type' => 'image',
                    'required' => 0,
                    'allow_multipart_rest_upload' => 1,
                ],
                [
                    'key' => 'field_upload_regression_gallery',
                    'name' => 'gallery',
                    'type' => 'gallery',
                    'required' => 0,
                    'allow_multipart_rest_upload' => 1,
                ],
            ],
        ]);
        $this->receiver = new Receiver();
        $this->receiver->addHooks();
        foreach (['sponsor-offerings', 'sponsor-assignments'] as $type) {
            register_rest_route('wp/v2', '/' . $type, [
                'methods' => 'POST',
                'permission_callback' => fn () => $this->allowed,
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
                    $this->saveCount++;

                    return new WP_REST_Response(['id' => 123, 'acf' => $request['acf']], 201);
                },
            ], true);
        }
        add_action(Receiver::ACTION_AFTER_INSERT, function ($postId) {
            $this->notifications[] = (int) $postId;
        });
    }

    public function tear_down(): void
    {
        remove_filter('rest_pre_dispatch', [$this->receiver, 'preDispatch'], 1);
        remove_filter('rest_request_before_callbacks', [$this->receiver, 'beforeCallbacks'], 10);
        remove_filter('rest_dispatch_request', [$this->receiver, 'dispatchRequest'], 10);
        remove_filter('rest_request_after_callbacks', [$this->receiver, 'afterCallbacks'], 10);
        remove_filter('rest_post_dispatch', [$this->receiver, 'postDispatch'], 10);
        remove_all_actions(Receiver::ACTION_AFTER_INSERT);
        acf_remove_local_field_group('group_upload_regression');
        foreach ($this->tempFiles as $path) {
            $this->deleteTempFile($path);
        }
        parent::tear_down();
    }

    private function request(string $type = 'sponsor-offerings', ?string $uuid = null): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/' . $type);
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', $uuid ?? wp_generate_uuid4());
        $request->set_body_params(['acf' => ['image' => '$file:hero']]);
        $path = $this->tempImage();
        $request->set_file_params(['_acf_rest_files' => ['hero' => [
            'name' => 'test-image.jpg', 'type' => 'image/jpeg',
            'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path),
        ]]]);
        return $request;
    }

    private function tempImage(): string
    {
        $path = wp_tempnam('test-image.jpg');
        copy(DIR_TESTDATA . '/images/canola.jpg', $path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * The receiver deletes leftover temp files itself; clean up defensively
     * without warnings when it already did.
     */
    private function deleteTempFile(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function idempotencyOption(WP_REST_Request $request): string
    {
        $method = new \ReflectionMethod(Receiver::class, 'idempotencyOptionName');

        return (string) $method->invoke($this->receiver, $request);
    }

    /**
     * The registered post type names, read from the registering classes so
     * the fixtures track the plugin registration instead of hardcoding it.
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
            self::assertSame([123], $this->notifications);
            wp_delete_attachment($id, true);
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
        self::assertSame([], $this->notifications);
    }

    public function testPermissionDenialDoesNotResolveUploadOrClaim(): void
    {
        $this->allowed = false;
        $request = $this->request();
        $response = rest_get_server()->dispatch($request);
        self::assertSame(403, $response->get_status());
        self::assertSame([null], $this->validated);
        self::assertFalse($this->saved);

        // No claim may exist: replays must never be served before the native
        // endpoint permission check passed.
        $option = $this->idempotencyOption($request);
        self::assertFalse(get_option($option));
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
    }

    public function testCompletedClaimReplaysCreatedResourceExactlyOnce(): void
    {
        $uuid = wp_generate_uuid4();

        $first = rest_get_server()->dispatch($this->request(type: 'sponsor-offerings', uuid: $uuid));
        self::assertSame(201, $first->get_status());
        self::assertSame(1, $this->saveCount);
        self::assertSame([123], $this->notifications);
        wp_delete_attachment((int) $first->get_data()['acf']['image'], true);

        // A retry with the same key replays the created resource without
        // running the native callback or firing the notification again.
        $second = rest_get_server()->dispatch($this->request(type: 'sponsor-offerings', uuid: $uuid));
        self::assertSame(201, $second->get_status());
        self::assertSame(['id' => 123], $second->get_data());
        self::assertSame(1, $this->saveCount);
        self::assertSame([123], $this->notifications);
    }

    public function testActiveClaimAnswers425WithRetryAfterAndKeepsTheLock(): void
    {
        $request = $this->request();
        $option = $this->idempotencyOption($request);
        add_option($option, [
            'status' => 'active',
            'owner' => 'another-request',
            'expires_at' => time() + 600,
            'saved_post_id' => null,
        ], '', 'no');

        $response = rest_get_server()->dispatch($request);

        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_in_progress', $response->get_data()['code'] ?? null);
        self::assertNotEmpty($response->get_headers()['Retry-After'] ?? null);
        self::assertFalse($this->saved);
        self::assertSame([], $this->notifications);

        // Another owner's claim is never released by this request.
        $state = get_option($option);
        self::assertSame('active', $state['status']);
        self::assertSame('another-request', $state['owner']);
    }

    public function testMixedGalleryKeepsExistingIdsAndOrder(): void
    {
        $request = $this->request();
        $request->set_body_params(['acf' => [
            'image' => '$file:hero',
            'gallery' => [123, '$file:hero', 456],
        ]]);

        $response = rest_get_server()->dispatch($request);

        self::assertSame(201, $response->get_status());
        $acf = $response->get_data()['acf'];
        $attachmentId = $acf['image'];
        self::assertGreaterThan(0, $attachmentId);
        self::assertSame([123, $attachmentId, 456], $acf['gallery']);
        self::assertSame('attachment', get_post_type($attachmentId));
        wp_delete_attachment($attachmentId, true);
    }

    public function testRootedNullPathClearsTheOptionalField(): void
    {
        $request = $this->request();
        $request->set_body_params([
            'acf' => ['image' => '$file:hero', 'optional_image' => 999],
            '_acf_rest_nulls' => ['acf[optional_image]'],
        ]);

        $response = rest_get_server()->dispatch($request);

        self::assertSame(201, $response->get_status());
        self::assertNull($response->get_data()['acf']['optional_image']);
        self::assertGreaterThan(0, $response->get_data()['acf']['image']);
        wp_delete_attachment((int) $response->get_data()['acf']['image'], true);
    }

    public function testEmptyListPathClearsTheGallery(): void
    {
        $request = $this->request();
        $request->set_body_params([
            'acf' => ['image' => '$file:hero'],
            '_acf_rest_empty' => ['acf[gallery]'],
        ]);

        $response = rest_get_server()->dispatch($request);

        self::assertSame(201, $response->get_status());
        self::assertSame([], $response->get_data()['acf']['gallery']);
        wp_delete_attachment((int) $response->get_data()['acf']['image'], true);
    }

    public function testNullPathCollidingWithAReferenceIsRejected(): void
    {
        $request = $this->request();
        $request->set_body_params([
            'acf' => ['image' => '$file:hero'],
            '_acf_rest_nulls' => ['acf[image]'],
        ]);

        $response = rest_get_server()->dispatch($request);

        self::assertSame(400, $response->get_status());
        self::assertFalse($this->saved);
    }
}
