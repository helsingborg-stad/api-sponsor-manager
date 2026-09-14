<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\IdempotencyStore;
use WP_UnitTestCase;

/**
 * Real-database regression for the insert-only fresh claim acquisition.
 *
 * Two contenders are staged to both observe the key as absent; exactly one
 * of them may acquire the claim (and reach the save that only an acquiring
 * request performs), and the loser's insert must be rejected as a duplicate
 * key without replacing the winner's option row. add_option() cannot prove
 * this: it executes "INSERT ... ON DUPLICATE KEY UPDATE", and with the
 * stale absence staged through the notoptions cache its pre-check passes
 * and the duplicate update replaces the winner's row and reports success.
 *
 * Requires the WordPress core test bootstrap. Named ...IntegrationTest so
 * the unit suite's IdempotencyStoreTest keeps its own fully qualified name.
 *
 * Run with: vendor/bin/phpunit --testsuite integration
 */
class IdempotencyStoreIntegrationTest extends WP_UnitTestCase
{
    private IdempotencyStore $store;

    /** @var list<string> */
    private array $options = [];

    /** @var list<int> */
    private array $posts = [];

    /** @var array<string, bool>|false */
    private array|false $notoptionsBackup = false;

    private ?string $stagedAbsenceOption = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->store = new IdempotencyStore();
    }

    public function tear_down(): void
    {
        foreach ($this->posts as $postId) {
            wp_delete_post($postId, true);
        }
        foreach ($this->options as $option) {
            delete_option($option);
        }

        // Restore the notoptions cache to its pre-staging state, also when
        // assertions failed earlier in the test.
        if ($this->stagedAbsenceOption !== null) {
            if ($this->notoptionsBackup === false) {
                wp_cache_delete('notoptions', 'options');
            } else {
                wp_cache_set('notoptions', $this->notoptionsBackup, 'options');
            }
        }

        parent::tear_down();
    }

    /**
     * The save an acquiring request performs, reduced to its durable
     * effect: exactly one created resource per acquired claim.
     */
    private function saveResource(): int
    {
        $postId = (int) wp_insert_post([
            'post_title' => 'acf rest upload integration ' . wp_generate_uuid4(),
            'post_status' => 'publish',
        ]);
        $this->posts[] = $postId;

        return $postId;
    }

    private function claimOption(): string
    {
        $option = 'acf_rest_upload_it_' . wp_generate_uuid4();
        $this->options[] = $option;

        return $option;
    }

    /**
     * Stage the loser's stale absence observation: its notoptions cache
     * entry says the option does not exist, exactly like a contender that
     * checked before the winner's row was inserted. Only this option is
     * added to an already existing cache array; the prior cache state is
     * backed up and restored in tear_down().
     */
    private function stageStaleAbsence(string $option): void
    {
        $notoptions = wp_cache_get('notoptions', 'options');
        $this->notoptionsBackup = is_array($notoptions) ? $notoptions : false;
        $this->stagedAbsenceOption = $option;

        $notoptions = is_array($notoptions) ? $notoptions : [];
        $notoptions[$option] = true;

        wp_cache_set('notoptions', $notoptions, 'options');
    }

    private function storedRawValue(string $option): string|false
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option
        ));

        return $raw === null ? false : (string) $raw;
    }

    public function testStagedContendersAcquireExactlyOnceAndTheLoserNeverReplacesTheWinner(): void
    {
        $option = $this->claimOption();
        $createdPosts = [];

        // Contender A observed the key as absent and acquires the claim;
        // acquiring is the only path to the save callback.
        $winner = $this->store->activeState('owner-winner', 600);
        self::assertFalse($this->store->read($option));

        if ($this->store->tryClaim($option, $winner)) {
            $createdPosts[] = $this->saveResource();
        }

        // Contender B observed absence before A's insert and only now
        // attempts its claim against the real database.
        $this->stageStaleAbsence($option);
        $loser = $this->store->activeState('owner-loser', 600);

        // The server rejects the duplicate insert with an error; suppress
        // its print output. last_error is recorded either way.
        $previousSuppress = $GLOBALS['wpdb']->suppress_errors(true);

        if ($this->store->tryClaim($option, $loser)) {
            $createdPosts[] = $this->saveResource();
        }

        $GLOBALS['wpdb']->suppress_errors($previousSuppress);

        // Exactly one acquisition/save callback, hence no loser post: the
        // loser's insert was rejected as a duplicate key by the server ...
        self::assertCount(1, $createdPosts);
        self::assertNotSame('', $GLOBALS['wpdb']->last_error);

        // ... and the winner's row is byte-identical in the database: with
        // add_option()'s INSERT ... ON DUPLICATE KEY UPDATE this held the
        // loser's bytes here and the save ran twice.
        self::assertSame(serialize($winner), $this->storedRawValue($option));

        // The single save resource is persisted and exists.
        self::assertSame(1, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE ID = %d",
            $createdPosts[0]
        )));

        // The losing process can read the winner's claim again: the post
        // attempt cache invalidation cleared the staged notoptions entry,
        // so the receiver answers 425 for the winner instead of re-claiming.
        $state = get_option($option);
        self::assertSame('owner-winner', $state['owner']);
        self::assertTrue($this->store->isActive($state));
    }

    public function testADatabaseFailureIsNotAnAcquisitionAndIsDistinguishableFromADuplicate(): void
    {
        global $wpdb;
        $option = $this->claimOption();
        $state = $this->store->activeState('owner-a', 600);

        // Force a real server error that is not a duplicate key: the target
        // table does not exist. Suppress the print output; last_error is
        // recorded either way.
        $realTable = $wpdb->options;
        $wpdb->options = $realTable . '_integration_failure';
        $previousSuppress = $wpdb->suppress_errors(true);

        try {
            self::assertFalse($this->store->tryClaim($option, $state));
        } finally {
            $wpdb->options = $realTable;
            $wpdb->suppress_errors($previousSuppress);
        }

        // wpdb recorded the failed statement; capture before any further
        // query resets it.
        $failure = $wpdb->last_error;

        // No row was written: a database failure never counts as an
        // acquisition. Unlike the duplicate case, the option row does not
        // exist afterwards, which is what distinguishes the two outcomes.
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            $option
        )));
        self::assertNotSame('', $failure);
    }
}
