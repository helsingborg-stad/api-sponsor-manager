<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\IdempotencyStore;
use Brain\Monkey\Functions;
use PluginTestCase\PluginTestCase;

class IdempotencyStoreTest extends PluginTestCase
{
    private InMemoryOptionRows $rows;

    private FakeWpdb $wpdb;

    private IdempotencyStore $store;

    public function setUp(): void
    {
        parent::setUp();

        $this->rows = new InMemoryOptionRows();
        $this->wpdb = new FakeWpdb($this->rows);
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->store = new IdempotencyStore();

        Functions\expect('get_option')->zeroOrMoreTimes()->andReturnUsing(function ($name, $default = false) {
            return $this->rows->rows[$name] ?? $default;
        });
        Functions\expect('add_option')->zeroOrMoreTimes()->andReturnUsing(function ($name, $value, $deprecated = '', $autoload = null) {
            if (array_key_exists($name, $this->rows->rows)) {
                return false;
            }

            $this->rows->rows[$name] = $value;

            return true;
        });
        Functions\expect('update_option')->zeroOrMoreTimes()->andReturnUsing(function ($name, $value, $autoload = null) {
            $this->rows->rows[$name] = $value;

            return true;
        });
        Functions\expect('delete_option')->zeroOrMoreTimes()->andReturnUsing(function ($name) {
            unset($this->rows->rows[$name]);

            return true;
        });
        Functions\when('wp_cache_delete')->alias(static fn (string $key, string $group = 'default'): bool => true);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    private function setOption(string $name, mixed $value): void
    {
        $this->rows->rows[$name] = $value;
    }

    private function option(string $name): mixed
    {
        return $this->rows->rows[$name] ?? false;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{status: string, owner: string, expires_at: int, saved_post_id: int|null}
     */
    private function activeState(string $owner, int $offsetSeconds = 60, array $overrides = []): array
    {
        return [
            'status' => 'active',
            'owner' => $owner,
            'expires_at' => time() + $offsetSeconds,
            'saved_post_id' => null,
            ...$overrides,
        ];
    }

    public function testNewOwnerTokensAreUnique(): void
    {
        self::assertNotSame($this->store->newOwner(), $this->store->newOwner());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->store->newOwner());
    }

    public function testClaimWinsOnlyWhenOptionIsMissing(): void
    {
        $state = $this->store->activeState('owner-a', 60);

        self::assertTrue($this->store->tryClaim('opt', $state));
        self::assertSame($state, $this->option('opt'));

        self::assertFalse($this->store->tryClaim('opt', $this->store->activeState('owner-b', 60)));
        self::assertSame('owner-a', $this->option('opt')['owner']);
    }

    public function testActiveStateCarriesExpiry(): void
    {
        $state = $this->store->activeState('owner-a', 900);

        self::assertSame('active', $state['status']);
        self::assertSame('owner-a', $state['owner']);
        self::assertSame(time() + 900, $state['expires_at']);
        self::assertNull($state['saved_post_id']);
    }

    public function testCompleteAndReleaseRequireTheOwnerToken(): void
    {
        $this->setOption('opt', $this->activeState('owner-a'));

        self::assertFalse($this->store->tryComplete('opt', 'owner-b', 7));
        self::assertFalse($this->store->tryRelease('opt', 'owner-b'));
        self::assertSame('active', $this->option('opt')['status']);

        self::assertTrue($this->store->tryComplete('opt', 'owner-a', 7));
        self::assertSame('complete', $this->option('opt')['status']);
        self::assertSame(7, $this->option('opt')['post_id']);
        self::assertTrue($this->option('opt')['notified']);

        // A completed claim can no longer be released by anybody.
        self::assertFalse($this->store->tryRelease('opt', 'owner-a'));
        self::assertSame('complete', $this->option('opt')['status']);
    }

    public function testRecordSavedPostRequiresOwnedActiveClaim(): void
    {
        $this->setOption('opt', $this->activeState('owner-a'));

        $this->store->tryRecordSavedPost('opt', 'owner-b', 7);
        self::assertNull($this->option('opt')['saved_post_id']);

        $this->store->tryRecordSavedPost('opt', 'owner-a', 7);
        self::assertSame(7, $this->option('opt')['saved_post_id']);
        self::assertSame('active', $this->option('opt')['status']);
    }

    public function testExpiredActiveClaims(): void
    {
        $this->setOption('opt', $this->activeState('owner-a', -10));

        self::assertTrue($this->store->isExpiredActive($this->store->read('opt')));
        self::assertTrue($this->store->isActive($this->store->read('opt')));

        $this->setOption('opt', $this->activeState('owner-a', 10));
        self::assertFalse($this->store->isExpiredActive($this->store->read('opt')));

        $this->setOption('opt', ['status' => 'complete', 'post_id' => 1]);
        self::assertFalse($this->store->isExpiredActive($this->store->read('opt')));
        self::assertFalse($this->store->isActive($this->store->read('opt')));
        self::assertSame(1, $this->store->completedPostId($this->store->read('opt')));

        self::assertFalse($this->store->isExpiredActive('junk'));
        self::assertFalse($this->store->isExpiredActive(null));
    }

    public function testAdoptTransfersAnExpiredClaimToCompleted(): void
    {
        $expected = $this->activeState('owner-a', -10);
        $this->setOption('opt', $expected);

        $owner = $this->store->tryAdopt('opt', 12, $expected);

        self::assertIsString($owner);
        self::assertSame('complete', $this->option('opt')['status']);
        self::assertSame(12, $this->option('opt')['post_id']);
        self::assertTrue($this->option('opt')['notified']);
    }

    public function testAdoptRejectsLiveClaims(): void
    {
        $expected = $this->activeState('owner-a', 60);
        $this->setOption('opt', $expected);

        self::assertFalse($this->store->tryAdopt('opt', 12, $expected));
        self::assertSame('active', $this->option('opt')['status']);
    }

    public function testConcurrentAdoptionLetsExactlyOneCallerNotify(): void
    {
        // Both adopters observed the same expired claim ...
        $expected = $this->activeState('owner-x', -10);
        $this->setOption('opt', $expected);

        $winner = $this->store->tryAdopt('opt', 12, $expected);
        self::assertIsString($winner);
        self::assertTrue($this->option('opt')['notified']);

        // ... the loser still holds the stale observation and must fail
        // closed, so it replays/answers 425 without firing a second time.
        $loser = $this->store->tryAdopt('opt', 12, $expected);

        self::assertFalse($loser);
        self::assertSame($winner, $this->option('opt')['owner']);
        self::assertSame(12, $this->option('opt')['post_id']);
        self::assertTrue($this->option('opt')['notified']);
    }

    public function testAdoptFailsWhenTheObservedStateDiffers(): void
    {
        $seeded = $this->activeState('owner-a', -5);
        $this->setOption('opt', $seeded);

        $staleObservation = $this->activeState('owner-a', -900);

        self::assertFalse($this->store->tryAdopt('opt', 12, $staleObservation));
        self::assertSame($seeded, $this->store->read('opt'));
    }

    public function testReclaimReplacesExpiredClaimWithFreshOwner(): void
    {
        $expected = $this->activeState('owner-a', -10);
        $this->setOption('opt', $expected);

        $fresh = $this->store->activeState('owner-b', 60);

        self::assertTrue($this->store->tryReclaim('opt', $fresh, $expected));
        self::assertSame($fresh, $this->store->read('opt'));
    }

    public function testReclaimRefusesWhenRowMatchesTheExpectedExpiredClaimButIsStillLive(): void
    {
        $expected = $this->activeState('owner-a', 60);
        $this->setOption('opt', $expected);

        // Same owner and status but inside the lock window: not expired.
        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $expected));
        self::assertSame('owner-a', $this->store->read('opt')['owner']);
    }

    public function testStaleContenderCannotDeleteAFreshOwnersClaim(): void
    {
        // Both contenders observed the same expired claim of owner-a ...
        $expected = $this->activeState('owner-a', -10);

        // ... contender B reclaims it first and becomes the fresh owner.
        $this->setOption('opt', $expected);
        self::assertTrue($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $expected));

        // ... stale contender A must fail closed and leave owner-b intact.
        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-a2', 60), $expected));
        self::assertSame('owner-b', $this->store->read('opt')['owner']);
        self::assertGreaterThan(time(), $this->store->read('opt')['expires_at']);
    }

    public function testReclaimFailsWhenTheObservedExpiryDiffers(): void
    {
        $seeded = $this->activeState('owner-a', -5);
        $this->setOption('opt', $seeded);

        $staleObservation = $this->activeState('owner-a', -900);

        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $staleObservation));
        self::assertSame($seeded, $this->store->read('opt'));
    }

    public function testReclaimFailsWhenTheRowVanishedOrHoldsForeignState(): void
    {
        // Row holds a completed claim: never a takeover target.
        $this->setOption('opt', ['status' => 'complete', 'post_id' => 3]);
        $expected = $this->activeState('owner-a', -10);

        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $expected));
        self::assertSame('complete', $this->store->read('opt')['status']);

        // No row at all: nothing was observed, nothing may be replaced.
        unset($this->rows->rows['opt']);
        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $expected));
        self::assertFalse($this->store->read('opt'));
    }

    public function testCasDetectsAConcurrentChangeBetweenReadAndWrite(): void
    {
        $this->setOption('opt', $this->activeState('owner-a'));

        // A concurrent transition lands right before our UPDATE is applied:
        // the stored bytes no longer match the observed state, the CAS must
        // affect zero rows and fail closed.
        $this->wpdb->onQuery = function (string $type, string $option): void {
            if ($type === 'UPDATE' && $option === 'opt') {
                $this->rows->rows['opt']['owner'] = 'raider';
            }
        };

        self::assertFalse($this->store->tryComplete('opt', 'owner-a', 7));
        self::assertSame('raider', $this->option('opt')['owner']);
        self::assertSame('active', $this->option('opt')['status']);
    }

    public function testWpdbUnavailableFailsClosed(): void
    {
        $expected = $this->activeState('owner-a', -10);
        $this->setOption('opt', $expected);
        $this->setOption('owned', $this->activeState('owner-a', 60));

        unset($GLOBALS['wpdb']);

        // Fresh claims (add_option) keep working ...
        self::assertTrue($this->store->tryClaim('fresh', $this->store->activeState('owner-a', 60)));

        // ... but no ownership-sensitive transition may succeed without the
        // compare-and-swap primitive: all fail closed instead of a fatal.
        self::assertFalse($this->store->tryComplete('owned', 'owner-a', 7));
        self::assertFalse($this->store->tryRelease('owned', 'owner-a'));
        self::assertFalse($this->store->tryReclaim('opt', $this->store->activeState('owner-b', 60), $expected));
        self::assertFalse($this->store->tryAdopt('opt', 12, $expected));

        $this->store->tryRecordSavedPost('owned', 'owner-a', 7);
        self::assertNull($this->option('owned')['saved_post_id']);

        self::assertSame('active', $this->option('owned')['status']);
        self::assertSame('active', $this->option('opt')['status']);
    }

    public function testWpdbErrorFailsClosed(): void
    {
        $this->setOption('owned', $this->activeState('owner-a', 60));
        $this->wpdb->failQuery = true;

        self::assertFalse($this->store->tryComplete('owned', 'owner-a', 7));
        self::assertFalse($this->store->tryRelease('owned', 'owner-a'));

        self::assertSame('active', $this->option('owned')['status']);
        self::assertNull($this->option('owned')['post_id'] ?? null);
    }

    public function testHistoryPruneDeleteIsConditional(): void
    {
        $store = new IdempotencyStore(IdempotencyStore::DEFAULT_HISTORY_OPTION, 2);

        $this->setOption('live', $this->activeState('owner-a', 60));
        $this->setOption('done', ['status' => 'complete', 'post_id' => 1]);
        $this->setOption('newest', ['status' => 'complete', 'post_id' => 3]);

        $store->trackClaim('done'); // history: [done]
        $store->trackClaim('live'); // history: [done, live]
        $store->trackClaim('newest'); // window overflow: "done" pruned and deleted

        self::assertSame(['live', 'newest'], $this->rows->rows['acf_rest_upload_history']);
        self::assertFalse($this->option('done'));
        self::assertNotFalse($this->option('live'));

        // A pruned live claim survives the conditional delete; an expired one
        // is removed.
        $this->setOption('expired', $this->activeState('owner-x', -10));

        $store->trackClaim('expired'); // prunes "live", which survives

        self::assertSame(['newest', 'expired'], $this->rows->rows['acf_rest_upload_history']);
        self::assertNotFalse($this->option('live'));

        $store->trackClaim('filler'); // prunes "newest", which is deleted

        self::assertSame(['expired', 'filler'], $this->rows->rows['acf_rest_upload_history']);
        self::assertFalse($this->option('newest'));
    }
}

/**
 * Shared in-memory row store for the option function stubs and the wpdb
 * emulator, so both layers observe exactly the same stored bytes.
 */
final class InMemoryOptionRows
{
    /** @var array<string, mixed> */
    public array $rows = [];
}

/**
 * Minimal wpdb emulator: applies the store's conditional UPDATE/DELETE
 * statements against the shared rows with MySQL affected-rows semantics
 * (one affected row on byte match with a changed value, zero otherwise).
 */
final class FakeWpdb
{
    public string $options = 'wp_options';

    public bool $failQuery = false;

    /** @var callable|null fn(string $type, string $option): void */
    public $onQuery;

    public function __construct(private readonly InMemoryOptionRows $rows)
    {
    }

    public function prepare(string $query, mixed ...$args): string
    {
        return (string) json_encode(['sql' => $query, 'args' => $args]);
    }

    public function query(string $prepared): int|false
    {
        if ($this->failQuery) {
            return false;
        }

        /** @var array{sql: string, args: list<mixed>} $decoded */
        $decoded = json_decode($prepared, true) ?? ['sql' => '', 'args' => []];
        $sql = (string) $decoded['sql'];
        $args = $decoded['args'];

        $type = str_contains($sql, 'UPDATE') ? 'UPDATE' : (str_contains($sql, 'DELETE FROM') ? 'DELETE' : 'UNKNOWN');

        if ($type === 'UNKNOWN') {
            return 0;
        }

        if ($type === 'UPDATE') {
            /** @var list<string> $args */
            [$newValue, $option, $oldValue] = $args;
        } else {
            /** @var list<string> $args */
            [$option, $oldValue] = $args;
            $newValue = null;
        }

        if (is_callable($this->onQuery)) {
            ($this->onQuery)($type, (string) $option);
        }

        $stored = array_key_exists((string) $option, $this->rows->rows)
            ? serialize($this->rows->rows[$option])
            : null;

        // Exact serialized byte match, mirroring "option_value = BINARY %s".
        if ($stored !== $oldValue) {
            return 0;
        }

        if ($type === 'DELETE') {
            unset($this->rows->rows[(string) $option]);

            return 1;
        }

        // MySQL reports zero affected rows when the value does not change.
        if ($stored === $newValue) {
            return 0;
        }

        $this->rows->rows[(string) $option] = unserialize((string) $newValue);

        return 1;
    }
}
