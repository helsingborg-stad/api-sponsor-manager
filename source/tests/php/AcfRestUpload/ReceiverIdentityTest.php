<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\IdempotencyStore;
use ApiSponsorManager\AcfRestUpload\Receiver;
use ApiSponsorManager\Assignment\PostType as AssignmentPostType;
use ApiSponsorManager\Offering\PostType as OfferingPostType;
use Brain\Monkey\Functions;
use PluginTestCase\PluginTestCase;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Regression coverage for the permanent create identity of the receiver.
 *
 * The identity lives in its own post meta, is recorded unresolved as a
 * checked write, is completed as a checked write before the claim completes,
 * and is the only replay source once the bounded claim history pruned the
 * option row. Adoption failures and initial identity failures are controlled
 * unresolved recovery states: the recorded resource blocks reclaim and
 * history GC, and a retry can never enter a fresh native creation.
 */
class ReceiverIdentityTest extends PluginTestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    private Receiver $receiver;

    private ReceiverIdentityRows $rows;

    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var array<int, WP_Post> */
    private array $existingPosts = [];

    /** @var array<string, mixed>|null */
    private array|null $lastPostQuery = null;

    /** @var list<string> Ordered durability events: identity writes and claim reads. */
    private array $events = [];

    /** @var array<string, int> */
    private array $optionReads = [];

    /** @var list<string> */
    private array $doActionCalls = [];

    private bool $failIdentityWrites = false;

    private ReceiverIdentityWpdb|null $wpdbFake = null;

    private array $insertListeners = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->receiver = new Receiver();
        $this->rows = new ReceiverIdentityRows();
        $this->events = [];
        $this->optionReads = [];
        $this->doActionCalls = [];
        $this->failIdentityWrites = false;
        $this->wpdbFake = null;

        Functions\when('current_user_can')->alias(static fn (): bool => true);
        Functions\when('get_current_user_id')->alias(static fn (): int => 1);
        Functions\when('is_wp_error')->alias(static fn (mixed $value): bool => $value instanceof \WP_Error);
        Functions\when('get_post_stati')->justReturn(['publish' => 'publish', 'draft' => 'draft', 'trash' => 'trash']);

        Functions\when('get_post_meta')->alias(function (int $postId, string $key, bool $single = false) {
            return $this->meta[$postId][$key] ?? '';
        });
        Functions\when('update_post_meta')->alias(function (int $postId, string $key, mixed $value) {
            if ($key === Receiver::META_CREATE_IDENTITY) {
                if ($this->failIdentityWrites) {
                    return false;
                }

                $this->meta[$postId][$key] = $value;
                $this->events[] = 'identity-written:' . $postId . ':' . ($value['status'] ?? '');

                return true;
            }

            $this->meta[$postId][$key] = $value;

            return true;
        });
        Functions\when('get_post')->alias(fn (int $postId): ?WP_Post => $this->existingPosts[$postId] ?? null);
        Functions\when('get_posts')->alias(function (array $query): array {
            $this->lastPostQuery = $query;

            $metaQuery = $query['meta_query'][0] ?? [];
            $needle = (string) ($metaQuery['value'] ?? '');

            if ($needle === ''
                || ($metaQuery['compare'] ?? '') !== 'LIKE'
                || ($metaQuery['key'] ?? '') !== Receiver::META_CREATE_IDENTITY) {
                return [];
            }

            foreach ($this->meta as $postId => $fields) {
                $identity = $fields[Receiver::META_CREATE_IDENTITY] ?? null;

                if (!is_array($identity)) {
                    continue;
                }

                // Emulate the serialized LIKE match on the quoted option name.
                if (str_contains('"' . ($identity['option'] ?? '') . '"', $needle)) {
                    return [(int) $postId];
                }
            }

            return [];
        });

        Functions\when('get_option')->alias(function (string $name, mixed $default = false) {
            if (str_starts_with($name, 'acf_rest_upload_')) {
                $this->optionReads[$name] = ($this->optionReads[$name] ?? 0) + 1;
                $this->events[] = 'option-read:' . $name;
            }

            return $this->rows->rows[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function (string $name, mixed $value, $autoload = null): bool {
            $this->rows->rows[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->rows->rows[$name]);

            return true;
        });
        Functions\when('wp_cache_delete')->alias(static fn (string $key, string $group = 'default'): bool => true);
        Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
            $this->insertListeners[$hook] = $callback;
            return true;
        });
        Functions\when('remove_action')->alias(static fn (...$args): bool => true);

        // A replay or a recovery failure must never notify; every test asserts
        // the recorded calls explicitly.
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): mixed {
            $this->doActionCalls[] = $hook;

            return null;
        });

        // A replay must never claim or touch the media library.
        Functions\expect('add_option')->never();
        Functions\expect('media_handle_sideload')->never();
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    public function testCreateInsertRecordsIdentityBeforeTheClaimResource(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $contextId = spl_object_id($request);
        $this->withContext($contextId, static function (array $context): array {
            $context['owner'] = 'owner-token';

            return $context;
        });

        $method = new \ReflectionMethod(Receiver::class, 'onRestInsert');
        $method->invoke($this->receiver, $contextId, new WP_Post(['ID' => 55]));

        // The permanent identity is recorded unresolved: the post alone
        // proves nothing yet.
        self::assertSame(
            ['option' => $option, 'status' => 'unresolved'],
            $this->meta[55][Receiver::META_CREATE_IDENTITY] ?? null
        );
        // The bounded key list is still written next to the identity.
        self::assertSame([self::UUID], $this->meta[55][Receiver::META_KEYS] ?? null);

        // The identity write is persisted BEFORE the claim may look at its
        // resource (tryRecordSavedPost): the durability order holds.
        self::assertSame(
            ['identity-written:55:unresolved', 'option-read:' . $option],
            $this->events
        );
        $this->assertNoNotification();
    }

    public function testCreateWithoutDurableIdentityStillAnchorsTheClaimResource(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $contextId = spl_object_id($request);
        $this->withContext($contextId, static function (array $context): array {
            $context['owner'] = 'owner-token';

            return $context;
        });

        // The identity write fails (broken meta persistence).
        $this->failIdentityWrites = true;

        $method = new \ReflectionMethod(Receiver::class, 'onRestInsert');
        $method->invoke($this->receiver, $contextId, new WP_Post(['ID' => 55]));

        // Fail closed on the identity and the key list, but the claim still
        // anchors the resource: that record is the durable state which
        // blocks history GC and reclaim (see the full sequence regression).
        self::assertArrayNotHasKey(Receiver::META_CREATE_IDENTITY, $this->meta[55] ?? []);
        self::assertArrayNotHasKey(Receiver::META_KEYS, $this->meta[55] ?? []);
        self::assertArrayHasKey($option, $this->optionReads);
        self::assertSame(['option-read:' . $option], $this->events);
        $this->assertNoNotification();
    }

    public function testUpdateInsertWritesOnlyTheBoundedKeyList(): void
    {
        $request = $this->request(route: '/wp/v2/sponsor-offerings/44');
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $contextId = spl_object_id($request);
        $this->withContext($contextId, static function (array $context): array {
            $context['owner'] = 'owner-token';

            return $context;
        });

        $method = new \ReflectionMethod(Receiver::class, 'onRestInsert');
        $method->invoke($this->receiver, $contextId, new WP_Post(['ID' => 44]));

        self::assertSame([self::UUID], $this->meta[44][Receiver::META_KEYS] ?? null);
        self::assertArrayNotHasKey(Receiver::META_CREATE_IDENTITY, $this->meta[44] ?? []);
    }

    public function testPostKeyListStaysBoundedAtTenKeys(): void
    {
        $method = new \ReflectionMethod(Receiver::class, 'recordPostKey');

        for ($i = 1; $i <= 12; $i++) {
            $method->invoke($this->receiver, 7, sprintf('22222222-2222-4222-8222-%012d', $i));
        }

        $keys = $this->meta[7][Receiver::META_KEYS];

        self::assertCount(10, $keys);
        self::assertSame('22222222-2222-4222-8222-000000000003', $keys[0]);
        self::assertSame('22222222-2222-4222-8222-000000000012', $keys[9]);
    }

    public function testCompletionStampsOnlyTheMatchingScope(): void
    {
        $complete = new \ReflectionMethod(Receiver::class, 'completeCreateIdentity');

        // A foreign claim scope fails closed and is never rewritten.
        $this->meta[9][Receiver::META_CREATE_IDENTITY] = ['option' => 'acf_rest_upload_scope-a', 'status' => 'unresolved'];
        self::assertFalse($complete->invoke($this->receiver, 9, 'acf_rest_upload_scope-b'));
        self::assertSame('unresolved', $this->meta[9][Receiver::META_CREATE_IDENTITY]['status']);

        // The matching scope is upgraded to complete, as a checked write.
        self::assertTrue($complete->invoke($this->receiver, 9, 'acf_rest_upload_scope-a'));
        self::assertSame(
            ['option' => 'acf_rest_upload_scope-a', 'status' => 'complete'],
            $this->meta[9][Receiver::META_CREATE_IDENTITY]
        );

        // An already complete identity counts as persisted without writing:
        // it survives even with the meta layer failing.
        $this->failIdentityWrites = true;
        self::assertTrue($complete->invoke($this->receiver, 9, 'acf_rest_upload_scope-a'));
        self::assertSame(
            ['option' => 'acf_rest_upload_scope-a', 'status' => 'complete'],
            $this->meta[9][Receiver::META_CREATE_IDENTITY]
        );
    }

    public function testFinalizeStampsIdentityCompleteBeforeClaimCompletion(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $contextId = spl_object_id($request);
        $this->withContext($contextId, static function (array $context): array {
            $context['owner'] = 'owner-token';
            $context['savedPostId'] = 77;

            return $context;
        });

        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => $option, 'status' => 'unresolved'];

        $native = new WP_REST_Response(['id' => 77], 201);
        $replacement = $this->receiver->afterCallbacks($native, [], $request);

        // The identity was completed first; only then did the claim
        // completion read its row. No CAS winner here, so no notification,
        // and the native success response is kept.
        self::assertSame(
            ['identity-written:77:complete', 'option-read:' . $option],
            $this->events
        );
        self::assertSame(
            ['option' => $option, 'status' => 'complete'],
            $this->meta[77][Receiver::META_CREATE_IDENTITY]
        );
        self::assertSame($native, $replacement);
        $this->assertNoNotification();

        $this->receiver->postDispatch($replacement, null, $request);
    }

    public function testFinalizeAnswersRecoveryPendingWhenIdentityCannotBeCompleted(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $contextId = spl_object_id($request);
        $this->withContext($contextId, static function (array $context): array {
            $context['owner'] = 'owner-token';
            $context['savedPostId'] = 77;

            return $context;
        });

        // The identity cannot be completed (broken meta persistence).
        $this->failIdentityWrites = true;

        $native = new WP_REST_Response(['id' => 77], 201);
        $replacement = $this->receiver->afterCallbacks($native, [], $request);
        $this->receiver->postDispatch($replacement, null, $request);

        // No false success: the native response is replaced by the
        // controlled recovery-pending response, the claim completion never
        // even read its row, and no notification fired.
        self::assertInstanceOf(WP_REST_Response::class, $replacement);
        self::assertNotSame($native, $replacement);
        self::assertSame(425, $replacement->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $replacement->get_data()['code'] ?? null);
        self::assertArrayNotHasKey($option, $this->optionReads);
        self::assertArrayNotHasKey(Receiver::META_CREATE_IDENTITY, $this->meta[77] ?? []);
        self::assertSame([], $this->events);
        $this->assertNoNotification();
    }

    public function testInterruptedInsertNeverProvesNativeCompletion(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);

        // An interrupted request left an expired claim with the recorded
        // resource and an unresolved identity.
        $this->rows->rows[$option] = [
            'status' => 'active',
            'owner' => 'interrupted-owner',
            'expires_at' => time() - 10,
            'saved_post_id' => 77,
        ];
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => $option, 'status' => 'unresolved'];

        $response = $this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []);

        // rest_insert runs before ACF saving. Even with a working CAS path,
        // a post and unresolved identity cannot prove the operation succeeded.
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code']);
        self::assertSame(
            ['option' => $option, 'status' => 'unresolved'],
            $this->meta[77][Receiver::META_CREATE_IDENTITY]
        );
        self::assertSame('interrupted-owner', $this->rows->rows[$option]['owner']);
        self::assertSame([], $this->wpdbFake->queries);
        $this->assertNoNotification();
    }

    public function testAdoptionFailsClosedOnAForeignIdentity(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $expired = [
            'status' => 'active',
            'owner' => 'interrupted-owner',
            'expires_at' => time() - 10,
            'saved_post_id' => 77,
        ];

        $this->rows->rows[$option] = $expired;
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => 'acf_rest_upload_foreign', 'status' => 'complete'];

        $response = $this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []);

        // Fail closed with the controlled recovery-pending response: the
        // foreign identity is untouched and the claim row is untouched.
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code'] ?? null);
        self::assertSame(
            ['option' => 'acf_rest_upload_foreign', 'status' => 'complete'],
            $this->meta[77][Receiver::META_CREATE_IDENTITY]
        );
        self::assertSame($expired, $this->rows->rows[$option]);

        // Only the acquire check and the adoption lookup read the row: the
        // reclaim path was never entered.
        self::assertSame(['option-read:' . $option, 'option-read:' . $option], $this->events);
        $this->assertNoNotification();
    }

    public function testAdoptionFailureNeverReclaimsWithAnOperationalReclaimPath(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;

        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        $expired = [
            'status' => 'active',
            'owner' => 'interrupted-owner',
            'expires_at' => time() - 10,
            'saved_post_id' => 77,
        ];

        $this->rows->rows[$option] = $expired;
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => 'acf_rest_upload_foreign', 'status' => 'complete'];

        $response = $this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []);

        // The controlled unresolved recovery state is answered retryable.
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code'] ?? null);

        // The recorded resource blocks the takeover: the claim row is
        // byte-identical and no fresh claim was inserted.
        self::assertSame($expired, $this->rows->rows[$option]);
        self::assertSame(0, $this->insertCount());
        $this->assertNoNotification();

        // Operationality probe: with the same rows and wpdb the reclaim CAS
        // does succeed, so the untouched row above was a decision of the
        // receiver, not a missing database path.
        $store = new IdempotencyStore();
        self::assertTrue($store->tryReclaim($option, $store->activeState('probe', 60), $expired));
        self::assertSame('probe', $this->rows->rows[$option]['owner']);
        self::assertSame(0, $this->insertCount());
    }

    public function testIdentityFailureRetainsStateAcrossGcAndRetriesNeverRecreate(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;

        // Phase A: the request acquires a fresh claim (operational insert).
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);
        self::assertNull($this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []));

        $contextId = spl_object_id($request);
        $row = $this->rows->rows[$option];

        self::assertSame('active', $row['status']);
        self::assertNull($row['saved_post_id']);
        self::assertSame(1, $this->insertCount());

        // Phase B: WordPress created the post, the identity write fails, and
        // the claim still anchors the resource as the durable blocker.
        $this->failIdentityWrites = true;

        $method = new \ReflectionMethod(Receiver::class, 'onRestInsert');
        $method->invoke($this->receiver, $contextId, new WP_Post(['ID' => 77]));
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);

        $row = $this->rows->rows[$option];

        self::assertSame(77, $row['saved_post_id']);
        self::assertArrayNotHasKey(Receiver::META_CREATE_IDENTITY, $this->meta[77] ?? []);

        // Phase C: the native callback returned success, but finalization
        // must not deliver a false success.
        $native = new WP_REST_Response(['id' => 77], 201);
        $replacement = $this->receiver->afterCallbacks($native, [], $request);
        $this->receiver->postDispatch($replacement, null, $request);

        self::assertInstanceOf(WP_REST_Response::class, $replacement);
        self::assertNotSame($native, $replacement);
        self::assertSame(425, $replacement->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $replacement->get_data()['code'] ?? null);
        $this->assertNoNotification();

        // Phase D: the claim expires.
        $this->rows->rows[$option]['expires_at'] = time() - 10;

        // Phase E: a history cleanup attempt prunes the claim out of the
        // bounded list but must never delete the resource-anchored row.
        $gc = new IdempotencyStore(IdempotencyStore::DEFAULT_HISTORY_OPTION, 2);
        $gc->trackClaim('gc-a');
        $gc->trackClaim('gc-b');

        self::assertSame(['gc-a', 'gc-b'], $this->rows->rows['acf_rest_upload_history']);
        self::assertSame(77, $this->rows->rows[$option]['saved_post_id']);

        // Phase F: a retry with a still-broken meta layer is answered
        // retryable; the row is untouched and no fresh claim is inserted.
        $stuck = $this->rows->rows[$option];

        $retry = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $retry));
        $response = $this->receiver->dispatchRequest(null, $retry, '/wp/v2/sponsor-offerings', []);
        $this->receiver->postDispatch($response, null, $retry);

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code'] ?? null);
        self::assertSame($stuck, $this->rows->rows[$option]);
        self::assertSame(1, $this->insertCount());
        $this->assertNoNotification();

        // Restoring the meta layer does not establish that native saving
        // completed. The operation stays unresolved until explicit recovery.
        $this->failIdentityWrites = false;

        $recovered = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $recovered));
        $response = $this->receiver->dispatchRequest(null, $recovered, '/wp/v2/sponsor-offerings', []);
        $this->receiver->postDispatch($response, null, $recovered);

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('active', $this->rows->rows[$option]['status']);
        self::assertArrayNotHasKey(Receiver::META_CREATE_IDENTITY, $this->meta[77] ?? []);
        $this->assertNoNotification();
        self::assertSame(1, $this->insertCount());
    }

    public function testFailedIdentityAndResourceWritesCannotBePrunedOrReclaimed(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request();
        $this->receiver->preDispatch(null, null, $request);
        self::assertNull($this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []));
        $option = $this->idempotencyOption($request);
        $this->failIdentityWrites = true;
        $this->wpdbFake->failUpdates = true;
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        (new \ReflectionMethod(Receiver::class, 'onRestInsert'))->invoke(
            $this->receiver, spl_object_id($request), $this->existingPosts[77]
        );
        $response = $this->receiver->afterCallbacks(new WP_REST_Response(['id' => 77], 201), [], $request);
        self::assertSame(425, $response->get_status());
        self::assertNull($this->rows->rows[$option]['saved_post_id']);
        $this->receiver->postDispatch($response, null, $request);

        // Persistence works again: neither GC nor reclaim may mistake the
        // absent saved_post_id for proof that the native callback did no work.
        $this->wpdbFake->failUpdates = false;
        $this->failIdentityWrites = false;
        $this->rows->rows[$option]['expires_at'] = time() - 10;
        $expired = $this->rows->rows[$option];
        $gc = new IdempotencyStore(IdempotencyStore::DEFAULT_HISTORY_OPTION, 1);
        $gc->trackClaim('later-operation');
        self::assertSame($expired, $this->rows->rows[$option] ?? null);
        $retry = $this->request();
        $this->receiver->preDispatch(null, null, $retry);
        $response = $this->receiver->dispatchRequest(null, $retry, '/wp/v2/sponsor-offerings', []);
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code']);
        self::assertSame($expired, $this->rows->rows[$option]);
        self::assertSame(1, $this->insertCount());
        $this->assertNoNotification();
    }

    public function testCompletedIdentityReplaysAfterTheClaimRowAgedOut(): void
    {
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);

        // The claim row was pruned as history: no option state answers.
        self::assertFalse($this->rows->rows[$option] ?? false);

        // The durable identity proves the original create completed.
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => $option, 'status' => 'complete'];

        $response = $this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []);

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(201, $response->get_status());
        self::assertSame(['id' => 77], $response->get_data());

        $this->receiver->afterCallbacks($response, [], $request);
        $this->receiver->postDispatch($response, null, $request);

        // The lookup used the identity meta and the scoped option name.
        self::assertSame(Receiver::META_CREATE_IDENTITY, $this->lastPostQuery['meta_query'][0]['key'] ?? null);
        self::assertSame('"' . $option . '"', $this->lastPostQuery['meta_query'][0]['value'] ?? null);
        $this->assertNoNotification();
    }

    public function testUnresolvedIdentityDoesNotProveSuccess(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request();
        self::assertNull($this->receiver->preDispatch(null, null, $request));

        $option = $this->idempotencyOption($request);

        // The request was interrupted after the post was created: the
        // identity exists, but it never completed.
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $this->meta[77][Receiver::META_CREATE_IDENTITY] = ['option' => $option, 'status' => 'unresolved'];

        $response = $this->receiver->dispatchRequest(null, $request, '/wp/v2/sponsor-offerings', []);

        // An unresolved identity blocks a new create, even without a claim.
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code'] ?? null);
        self::assertSame(0, $this->insertCount());

        // The identity was never stamped complete on this path.
        self::assertSame('unresolved', $this->meta[77][Receiver::META_CREATE_IDENTITY]['status']);
        $this->assertNoNotification();
    }

    /**
     * Regression: the identity lookup must query the registered post types
     * for the scoped option name, like the interrupted-claim recovery lookup.
     */
    public function testIdentityLookupQueriesTheRegisteredPostTypes(): void
    {
        $method = new \ReflectionMethod(Receiver::class, 'findCreatePost');
        $method->invoke($this->receiver, 'acf_rest_upload_scope');

        self::assertIsArray($this->lastPostQuery);
        self::assertEqualsCanonicalizing(
            [$this->registeredAssignmentType(), $this->registeredOfferingType()],
            $this->lastPostQuery['post_type'] ?? null
        );
        self::assertSame(['publish', 'draft', 'trash'], $this->lastPostQuery['post_status'] ?? null);
    }

    public function testOriginalCreateSurvives101CompletedUpdates(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $post = new WP_Post(['ID' => 77]);
        $this->existingPosts[77] = $post;
        $create = $this->request();
        $option = $this->idempotencyOption($create);

        $save = function (WP_REST_Request $request, bool $creating) use ($post): void {
            self::assertNull($this->receiver->preDispatch(null, null, $request));
            self::assertNull($this->receiver->dispatchRequest(null, $request, $request->get_route(), []));
            ($this->insertListeners['rest_insert_offering'])($post, $request, $creating);
            $response = $this->receiver->afterCallbacks(new WP_REST_Response(['id' => 77], $creating ? 201 : 200), [], $request);
            self::assertSame($creating ? 201 : 200, $response->get_status());
            $this->receiver->postDispatch($response, null, $request);
        };
        $save($create, true);
        $identity = $this->meta[77][Receiver::META_CREATE_IDENTITY];
        self::assertSame(['option' => $option, 'status' => 'complete'], $identity);
        for ($i = 1; $i <= 101; $i++) {
            $save($this->request('/wp/v2/sponsor-offerings/77', sprintf('22222222-2222-4222-8222-%012d', $i)), false);
        }
        self::assertArrayNotHasKey($option, $this->rows->rows);
        self::assertCount(100, $this->rows->rows[IdempotencyStore::DEFAULT_HISTORY_OPTION]);
        self::assertCount(10, $this->meta[77][Receiver::META_KEYS]);
        self::assertNotContains(self::UUID, $this->meta[77][Receiver::META_KEYS]);
        self::assertSame($identity, $this->meta[77][Receiver::META_CREATE_IDENTITY]);
        $notifications = $this->doActionCalls;
        self::assertCount(102, $notifications);
        $retry = $this->request();
        // Include a valid upload reference on replay so the no-sideload
        // expectation guards a reachable media path, not an empty upload list.
        $group = ['key' => 'group_image', 'show_in_rest' => 1];
        $field = ['key' => 'field_image', 'name' => 'image', 'type' => 'image', 'parent' => 'group_image', 'allow_multipart_rest_upload' => 1];
        Functions\when('acf_get_field_groups')->justReturn([$group]);
        Functions\when('acf_get_field_group')->justReturn($group);
        Functions\when('acf_get_fields')->justReturn([$field]);
        Functions\when('acf_get_field_type')->justReturn((object) ['show_in_rest' => true]);
        Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
        $retry->set_body_params(['acf' => ['image' => '$file:hero']]);
        $retry->set_file_params(['_acf_rest_files' => ['hero' => [
            'name' => 'photo.jpg', 'type' => 'image/jpeg',
            'tmp_name' => sys_get_temp_dir() . '/identity-replay-' . bin2hex(random_bytes(16)),
            'error' => UPLOAD_ERR_OK, 'size' => 5,
        ]]]);
        self::assertNull($this->receiver->preDispatch(null, null, $retry));
        $response = $this->receiver->dispatchRequest(null, $retry, $retry->get_route(), []);
        self::assertInstanceOf(WP_REST_Response::class, $response, 'A non-null response bypasses native creation.');
        self::assertSame(201, $response->get_status());
        self::assertSame(['id' => 77], $response->get_data());
        $this->receiver->afterCallbacks($response, [], $retry);
        $this->receiver->postDispatch($response, null, $retry);
        self::assertSame($notifications, $this->doActionCalls);
        self::assertSame(102, $this->insertCount());
    }

    public function testExpiredUpdateCannotInferSuccessFromSavedPostId(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request('/wp/v2/sponsor-offerings/77');
        $this->receiver->preDispatch(null, null, $request);
        $option = $this->idempotencyOption($request);
        $this->existingPosts[77] = new WP_Post(['ID' => 77]);
        $state = ['status' => 'active', 'owner' => 'interrupted', 'expires_at' => time() - 10, 'saved_post_id' => 77];
        $this->rows->rows[$option] = $state;
        $response = $this->receiver->dispatchRequest(null, $request, $request->get_route(), []);
        self::assertSame(425, $response->get_status());
        self::assertSame($state, $this->rows->rows[$option]);
        self::assertSame([], $this->wpdbFake->queries);
        $this->assertNoNotification();
    }

    public function testCompleteIdentityFinishesExpiredClaimAndNotifiesOnlyOnce(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request();
        $this->receiver->preDispatch(null, null, $request);
        self::assertNull($this->receiver->dispatchRequest(null, $request, $request->get_route(), []));
        $option = $this->idempotencyOption($request);
        $post = new WP_Post(['ID' => 77]);
        $this->existingPosts[77] = $post;
        ($this->insertListeners['rest_insert_offering'])($post, $request, true);

        // Native success persists the complete identity, but claim completion
        // fails. This is the only interrupted state that permits adoption.
        $this->wpdbFake->failUpdates = true;
        $response = $this->receiver->afterCallbacks(new WP_REST_Response(['id' => 77], 201), [], $request);
        $this->receiver->postDispatch($response, null, $request);
        self::assertSame('complete', $this->meta[77][Receiver::META_CREATE_IDENTITY]['status']);
        self::assertSame('active', $this->rows->rows[$option]['status']);
        $this->assertNoNotification();
        $this->wpdbFake->failUpdates = false;
        $this->rows->rows[$option]['expires_at'] = time() - 10;

        for ($i = 0; $i < 2; $i++) {
            $retry = $this->request();
            $this->receiver->preDispatch(null, null, $retry);
            $response = $this->receiver->dispatchRequest(null, $retry, $retry->get_route(), []);
            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(201, $response->get_status());
            self::assertSame(['id' => 77], $response->get_data());
            $this->receiver->afterCallbacks($response, [], $retry);
            $this->receiver->postDispatch($response, null, $retry);
            self::assertSame([Receiver::ACTION_AFTER_INSERT], $this->doActionCalls);
        }
        self::assertSame('complete', $this->rows->rows[$option]['status']);
        self::assertSame(1, $this->insertCount());
    }

    public function testFailedPostDeletionRemainsUnresolvedAfterGc(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $request = $this->request();
        $this->receiver->preDispatch(null, null, $request);
        self::assertNull($this->receiver->dispatchRequest(null, $request, $request->get_route(), []));
        $option = $this->idempotencyOption($request);
        $post = new WP_Post(['ID' => 77]);
        $this->existingPosts[77] = $post;
        ($this->insertListeners['rest_insert_offering'])($post, $request, true);
        Functions\expect('wp_delete_post')->once()->with(77, true)->andReturn(false);
        $failure = new \WP_Error('native_save_failed', 'Saving failed.', ['status' => 500]);
        self::assertSame($failure, $this->receiver->afterCallbacks($failure, [], $request));
        $this->receiver->postDispatch($failure, null, $request);
        self::assertSame('unresolved', $this->meta[77][Receiver::META_CREATE_IDENTITY]['status']);
        $this->rows->rows[$option]['expires_at'] = time() - 10;
        $expired = $this->rows->rows[$option];
        self::assertSame(77, $expired['saved_post_id']);
        (new IdempotencyStore(IdempotencyStore::DEFAULT_HISTORY_OPTION, 1))->trackClaim('later-operation');
        self::assertSame($expired, $this->rows->rows[$option]);
        $retry = $this->request();
        $this->receiver->preDispatch(null, null, $retry);
        $response = $this->receiver->dispatchRequest(null, $retry, $retry->get_route(), []);
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(425, $response->get_status());
        self::assertSame('acf_rest_upload_recovery_pending', $response->get_data()['code']);
        self::assertSame($expired, $this->rows->rows[$option]);
        self::assertSame(1, $this->insertCount());
        $this->assertNoNotification();
    }

    public function testDurableIdentityIsScopedAndNormalizesUuidCase(): void
    {
        $this->wpdbFake = new ReceiverIdentityWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdbFake;
        $uuid = 'abcdefab-abcd-4abc-8abc-abcdefabcdef';
        $request = $this->request(key: strtoupper($uuid));
        $this->receiver->preDispatch(null, null, $request);
        self::assertNull($this->receiver->dispatchRequest(null, $request, $request->get_route(), []));
        $post = new WP_Post(['ID' => 77]);
        $this->existingPosts[77] = $post;
        ($this->insertListeners['rest_insert_offering'])($post, $request, true);
        $response = $this->receiver->afterCallbacks(new WP_REST_Response(['id' => 77], 201), [], $request);
        $this->receiver->postDispatch($response, null, $request);
        $option = $this->idempotencyOption($request);
        // Independent expected scope, not the receiver's private helper.
        self::assertSame('acf_rest_upload_' . hash('sha256', '/wp/v2/sponsor-offerings|POST|1|' . $uuid), $option);
        unset($this->rows->rows[$option]);

        $retry = $this->request(key: $uuid);
        $this->receiver->preDispatch(null, null, $retry);
        $response = $this->receiver->dispatchRequest(null, $retry, $retry->get_route(), []);
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(['id' => 77], $response->get_data());
        $this->receiver->postDispatch($response, null, $retry);

        $otherRoute = $this->request('/wp/v2/sponsor-assignments', $uuid);
        $this->receiver->preDispatch(null, null, $otherRoute);
        self::assertNull($this->receiver->dispatchRequest(null, $otherRoute, $otherRoute->get_route(), []));
        $this->receiver->postDispatch(new WP_REST_Response([], 500), null, $otherRoute);
        Functions\when('get_current_user_id')->justReturn(2);
        $otherUser = $this->request(key: $uuid);
        $this->receiver->preDispatch(null, null, $otherUser);
        self::assertNull($this->receiver->dispatchRequest(null, $otherUser, $otherUser->get_route(), []));
        $this->receiver->postDispatch(new WP_REST_Response([], 500), null, $otherUser);
        self::assertSame([Receiver::ACTION_AFTER_INSERT], $this->doActionCalls);
        self::assertSame(3, $this->insertCount());
    }

    private function request(string $route = '/wp/v2/sponsor-offerings', ?string $key = self::UUID): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('X-ACF-Rest-Upload-Version', '1');

        if ($key !== null) {
            $request->set_header('Idempotency-Key', $key);
        }

        // No "$file:" markers, so the request needs no ACF or file fixtures.
        $request->set_body_params(['acf' => ['image' => 123]]);

        return $request;
    }

    private function idempotencyOption(WP_REST_Request $request): string
    {
        $method = new \ReflectionMethod(Receiver::class, 'idempotencyOptionName');

        return (string) $method->invoke($this->receiver, $request);
    }

    /**
     * Mutate one request context through the private contexts property.
     *
     * @param callable(array): array $mutate
     */
    private function withContext(int $contextId, callable $mutate): void
    {
        $property = new \ReflectionProperty(Receiver::class, 'contexts');
        $contexts = $property->getValue($this->receiver);
        $contexts[$contextId] = $mutate($contexts[$contextId]);
        $property->setValue($this->receiver, $contexts);
    }

    private function insertCount(): int
    {
        self::assertNotNull($this->wpdbFake);

        return count(array_filter(
            $this->wpdbFake->queries,
            static fn (string $query): bool => str_contains($query, 'INSERT INTO')
        ));
    }

    private function assertNoNotification(): void
    {
        self::assertSame([], $this->doActionCalls);
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
}

/**
 * Shared in-memory row store for the option function stubs and the wpdb
 * emulator, so both layers observe exactly the same stored bytes.
 */
final class ReceiverIdentityRows
{
    /** @var array<string, mixed> */
    public array $rows = [];
}

/**
 * Operational wpdb emulator for the receiver identity regressions: applies
 * the store's INSERT and conditional UPDATE/DELETE statements against the
 * shared rows with MySQL semantics, so the reclaim and adoption CAS paths
 * are live and a skipped reclaim is a receiver decision, not a missing
 * database path.
 */
final class ReceiverIdentityWpdb
{
    public bool $failUpdates = false;
    public string $options = 'wp_options';

    public string $last_error = '';

    private bool $suppress_errors = false;

    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly ReceiverIdentityRows $rows)
    {
    }

    /**
     * Mirror wpdb::suppress_errors(): it toggles the error display flag only
     * and returns the previous state.
     */
    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress_errors;
        $this->suppress_errors = $suppress;

        return $previous;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        return (string) json_encode(['sql' => $query, 'args' => $args]);
    }

    public function query(string $prepared): int|false
    {
        $this->last_error = '';
        $this->queries[] = $prepared;

        /** @var array{sql: string, args: list<mixed>} $decoded */
        $decoded = json_decode($prepared, true) ?? ['sql' => '', 'args' => []];
        $sql = (string) $decoded['sql'];
        $args = $decoded['args'];

        if (str_contains($sql, 'INSERT INTO')) {
            /** @var list<string> $args */
            [$option, $serializedValue] = $args;

            return $this->insert((string) $option, (string) $serializedValue);
        }

        if (str_contains($sql, 'UPDATE')) {
            if ($this->failUpdates) {
                return false;
            }
            /** @var list<string> $args */
            [$newValue, $option, $oldValue] = $args;

            return $this->casWrite((string) $option, (string) $oldValue, (string) $newValue);
        }

        if (str_contains($sql, 'DELETE FROM')) {
            /** @var list<string> $args */
            [$option, $oldValue] = $args;

            return $this->casWrite((string) $option, (string) $oldValue, null);
        }

        return 0;
    }

    /**
     * Emulate a plain insert-only INSERT against the unique option_name key:
     * a duplicate fails with a duplicate key error and never touches the
     * existing row.
     */
    private function insert(string $option, string $serializedValue): int|false
    {
        if (array_key_exists($option, $this->rows->rows)) {
            $this->last_error = "Duplicate entry '{$option}' for key 'option_name'";

            return false;
        }

        $this->rows->rows[$option] = unserialize($serializedValue);

        return 1;
    }

    /**
     * Apply a conditional UPDATE or DELETE: one affected row on an exact
     * serialized byte match, zero otherwise, mirroring
     * "option_value = BINARY %s".
     */
    private function casWrite(string $option, string $oldValue, string|null $newValue): int
    {
        $stored = array_key_exists($option, $this->rows->rows)
            ? serialize($this->rows->rows[$option])
            : null;

        if ($stored !== $oldValue) {
            return 0;
        }

        if ($newValue === null) {
            unset($this->rows->rows[$option]);

            return 1;
        }

        if ($stored === $newValue) {
            // MySQL reports zero affected rows when the value does not change.
            return 0;
        }

        $this->rows->rows[$option] = unserialize($newValue);

        return 1;
    }
}
