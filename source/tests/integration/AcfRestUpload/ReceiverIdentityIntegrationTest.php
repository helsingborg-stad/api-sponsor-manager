<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\IdempotencyStore;
use ApiSponsorManager\AcfRestUpload\Receiver;
use ApiSponsorManager\Offering\PostType as OfferingPostType;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Database-backed fixture for the permanent create identity.
 *
 * Proves on the real options table and post meta that a completed create
 * still replays after more than 100 unrelated operations pruned its claim
 * row as history, that unresolved claims are never pruned as ordinary
 * history, and that the identity is removed with its post.
 *
 * A fixture callback inserts posts and fires the REST insert hook. This is
 * not proof of native sponsor-controller saving, ACF saving, or media upload.
 * Replays must bypass that callback and leave notifications and posts unchanged.
 *
 * Requires the WordPress core test bootstrap. Named ...IntegrationTest so
 * the unit suite's ReceiverIdentityTest keeps its own fully qualified name.
 *
 * Run with: vendor/bin/phpunit --testsuite integration
 */
class ReceiverIdentityIntegrationTest extends WP_UnitTestCase
{
    private const ROUTE = '/wp/v2/sponsor-offerings';

    private Receiver $receiver;

    private IdempotencyStore $store;

    private int $saveCount = 0;

    /** @var list<int> */
    private array $notifications = [];

    /** @var list<string> */
    private array $options = [];

    /** @var list<int> */
    private array $posts = [];

    private WP_REST_Server $server;

    /** @var callable(int): void The completion listener this test registered. */
    private $notificationListener;

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->receiver = new Receiver();
        $this->receiver->addHooks();
        $this->store = new IdempotencyStore();

        $this->notificationListener = function ($postId): void {
            $this->notifications[] = (int) $postId;
        };
        add_action(Receiver::ACTION_AFTER_INSERT, $this->notificationListener);

        // This server belongs only to the fixture. Never replace global routes.
        $this->server = new WP_REST_Server();
        $postType = $this->registeredOfferingType();

        $this->server->register_route('wp/v2', self::ROUTE, [[
            'methods' => 'POST',
            'permission_callback' => static fn (): bool => true,
            'callback' => function (WP_REST_Request $request) use ($postType) {
                $this->saveCount++;

                $postId = (int) wp_insert_post([
                    'post_type' => $postType,
                    'post_status' => 'publish',
                    'post_title' => 'identity integration ' . wp_generate_uuid4(),
                ]);

                if ($postId === 0) {
                    return new WP_Error('rest_insert_failed', 'The post could not be created.', ['status' => 500]);
                }

                $this->posts[] = $postId;
                do_action('rest_insert_' . $postType, get_post($postId), $request, true);

                return new WP_REST_Response(['id' => $postId], 201);
            },
        ]]);
    }

    public function tear_down(): void
    {
        remove_filter('rest_pre_dispatch', [$this->receiver, 'preDispatch'], 1);
        remove_filter('rest_request_before_callbacks', [$this->receiver, 'beforeCallbacks'], 10);
        remove_filter('rest_dispatch_request', [$this->receiver, 'dispatchRequest'], 10);
        remove_filter('rest_request_after_callbacks', [$this->receiver, 'afterCallbacks'], 10);
        remove_filter('rest_post_dispatch', [$this->receiver, 'postDispatch'], 10);

        // Only the listener this test registered; the completion action is
        // shared production surface and is never bulk-removed here.
        remove_action(Receiver::ACTION_AFTER_INSERT, $this->notificationListener);

        foreach ($this->posts as $postId) {
            wp_delete_post($postId, true);
        }
        foreach ($this->options as $option) {
            delete_option($option);
        }
        delete_option(IdempotencyStore::DEFAULT_HISTORY_OPTION);

        parent::tear_down();
    }

    public function testCompletedCreateReplaysFromDurableIdentityAfterHistoryGc(): void
    {
        $uuid = wp_generate_uuid4();

        $first = $this->dispatch($uuid);

        self::assertSame(201, $first->get_status());
        self::assertSame(1, $this->saveCount);
        $postId = (int) $first->get_data()['id'];
        self::assertSame([$postId], $this->notifications);

        $option = $this->idempotencyOption($uuid);
        $this->options[] = $option;

        // The create identity is durable and complete on the post.
        self::assertSame(
            ['option' => $option, 'status' => 'complete'],
            get_post_meta($postId, Receiver::META_CREATE_IDENTITY, true)
        );

        // More than 100 unrelated later operations age the completed claim
        // out of the bounded history; the GC delete removes its option row.
        for ($i = 0; $i < 101; $i++) {
            $filler = 'acf_rest_upload_identity_gc_' . $i;
            $this->options[] = $filler;

            self::assertTrue($this->store->tryClaim($filler, $this->store->activeState('gc-' . $i, 600)));
            self::assertTrue($this->store->tryComplete($filler, 'gc-' . $i, $postId));
            $this->store->trackClaim($filler);
        }

        self::assertFalse(get_option($option));

        $resourceCount = $this->countResources();

        // A repeat of the create key replays the original response from the
        // durable identity: no second save, no second notification, no
        // second created resource.
        $second = $this->dispatch($uuid);

        self::assertSame(201, $second->get_status());
        self::assertSame(['id' => $postId], $second->get_data());
        self::assertSame(1, $this->saveCount);
        self::assertSame([$postId], $this->notifications);
        self::assertSame($resourceCount, $this->countResources());
    }

    public function testUnresolvedClaimSurvivesHistoryGcAndBlocksCreation(): void
    {
        $uuid = wp_generate_uuid4();
        $option = $this->idempotencyOption($uuid);
        $this->options[] = $option;

        // Stage a request that was interrupted right after WordPress created
        // its post: an expired claim with the recorded resource and an
        // unresolved create identity, exactly as the receiver records both.
        $postId = (int) wp_insert_post([
            'post_type' => $this->registeredOfferingType(),
            'post_status' => 'publish',
            'post_title' => 'unresolved identity integration',
        ]);
        $this->posts[] = $postId;

        $owner = 'interrupted-owner';
        self::assertTrue($this->store->tryClaim($option, $this->store->activeState($owner, 600)));
        $this->store->tryRecordSavedPost($option, $owner, $postId);
        $this->store->trackClaim($option);

        $expired = $this->store->read($option);
        self::assertSame($postId, $expired['saved_post_id']);
        $expired['expires_at'] = time() - 10;
        update_option($option, $expired);

        update_post_meta($postId, Receiver::META_CREATE_IDENTITY, [
            'option' => $option,
            'status' => 'unresolved',
        ]);

        // The unrelated history GC prunes the claim out of the bounded list
        // but must never delete the unresolved record.
        for ($i = 0; $i < 101; $i++) {
            $filler = 'acf_rest_upload_identity_gc_' . $i;
            $this->options[] = $filler;

            self::assertTrue($this->store->tryClaim($filler, $this->store->activeState('gc-' . $i, 600)));
            self::assertTrue($this->store->tryComplete($filler, 'gc-' . $i, $postId));
            $this->store->trackClaim($filler);
        }

        self::assertNotFalse(get_option($option));
        self::assertSame($expired, get_option($option));
        self::assertNotContains($option, get_option(IdempotencyStore::DEFAULT_HISTORY_OPTION));

        // Insertion is not proof of native/ACF completion. Retry must block.
        $response = $this->dispatch($uuid);

        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code']);
        self::assertSame(0, $this->saveCount);
        self::assertSame([], $this->notifications);

        self::assertSame($expired, get_option($option));
        self::assertSame(
            ['option' => $option, 'status' => 'unresolved'],
            get_post_meta($postId, Receiver::META_CREATE_IDENTITY, true)
        );
    }

    public function testPermanentIdentityIsRemovedWithItsPost(): void
    {
        $uuid = wp_generate_uuid4();

        $first = $this->dispatch($uuid);

        self::assertSame(201, $first->get_status());
        $postId = (int) $first->get_data()['id'];
        $identity = get_post_meta($postId, Receiver::META_CREATE_IDENTITY, true);
        self::assertSame('complete', $identity['status']);

        // Force the durable lookup and keep identity through the trash phase.
        delete_option($this->idempotencyOption($uuid));
        self::assertInstanceOf(\WP_Post::class, wp_trash_post($postId));
        self::assertSame('trash', get_post_status($postId));
        $replay = $this->dispatch($uuid);
        self::assertSame(201, $replay->get_status());
        self::assertSame(['id' => $postId], $replay->get_data());
        self::assertSame(1, $this->saveCount);
        self::assertSame([$postId], $this->notifications);
        self::assertSame($identity, get_post_meta($postId, Receiver::META_CREATE_IDENTITY, true));

        // Permanent deletion removes post meta; no custom delete hook is needed.
        wp_delete_post($postId, true);
        $this->posts = array_values(array_diff($this->posts, [$postId]));

        self::assertSame('', get_post_meta($postId, Receiver::META_CREATE_IDENTITY, true));
    }

    private function dispatch(string $uuid): WP_REST_Response
    {
        $request = $this->request($uuid);
        $this->options[] = $this->idempotencyOption($uuid);
        $response = $this->server->dispatch($request);

        // Core dispatch runs callback filters, not the outer post-dispatch hook.
        return $this->receiver->postDispatch($response, $this->server, $request);
    }

    /**
     * The number of posts on the destination type, as a duplicate-create
     * guard: a replay must not create any additional resource.
     *
     * @return int<0, max>
     */
    private function countResources(): int
    {
        $ids = get_posts([
            'post_type' => $this->registeredOfferingType(),
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);

        return count((array) $ids);
    }

    private function request(string $uuid): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_header('X-ACF-Rest-Upload-Version', '1');
        $request->set_header('Idempotency-Key', $uuid);
        $request->set_body_params(['acf' => ['image' => 123]]);

        return $request;
    }

    private function idempotencyOption(string $uuid): string
    {
        $method = new \ReflectionMethod(Receiver::class, 'idempotencyOptionName');

        return (string) $method->invoke($this->receiver, $this->request($uuid));
    }

    /**
     * The registered post type name, read from the registering class so the
     * fixture tracks the plugin registration instead of hardcoding it.
     */
    private function registeredOfferingType(): string
    {
        return (new class extends OfferingPostType {
            public function __construct()
            {
                // getName() reads no WordPress service; skip the constructor.
            }
        })->getName();
    }
}
