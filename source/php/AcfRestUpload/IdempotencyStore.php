<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

/**
 * Ownership-safe option storage for multipart upload idempotency claims.
 *
 * Claim states live in regular options. Every ownership-sensitive transition
 * (reclaim, adopt, complete, release, record, history prune) is executed as a
 * direct $wpdb conditional statement: the row is only replaced or deleted when
 * its stored value is byte-identical (BINARY comparison) to the serialized
 * state the caller observed, and the attempt reports success only when exactly
 * one row was affected. Concurrent contenders therefore serialize on the row:
 * the first transition wins, every loser observes zero affected rows and fails
 * closed, so a stale contender can never clobber a fresh owner and exactly one
 * caller can move an expired claim to complete/notified.
 *
 * The only non-CAS write is the initial fresh claim, which uses add_option()
 * and is arbitrated by the unique option_name index.
 *
 * Remaining boundary: the CAS guarantees process concurrency correctness. A
 * strict crash-safe exactly-once delivery (for example of the completion
 * notification) would additionally require a durable outbox.
 */
final class IdempotencyStore
{
    /**
     * Option that keeps a bounded, newest-last list of recent claim options.
     */
    public const DEFAULT_HISTORY_OPTION = 'acf_rest_upload_history';

    public function __construct(
        private readonly string $historyOption = self::DEFAULT_HISTORY_OPTION,
        private readonly int $historyLimit = 100
    ) {
    }

    /**
     * Generate a fresh owner token for a claim.
     */
    public function newOwner(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build the state stored while a request holds an idempotency claim.
     *
     * @return array{status: string, owner: string, expires_at: int, saved_post_id: int|null}
     */
    public function activeState(string $owner, int $ttl): array
    {
        return [
            'status' => 'active',
            'owner' => $owner,
            'expires_at' => time() + $ttl,
            'saved_post_id' => null,
        ];
    }

    /**
     * Read the raw stored state of a claim.
     */
    public function read(string $option): mixed
    {
        return get_option($option);
    }

    /**
     * Whether the stored state is a claim still inside its lock window.
     *
     * @param mixed $state
     */
    public function isActive(mixed $state): bool
    {
        return is_array($state) && ($state['status'] ?? null) === 'active';
    }

    /**
     * Whether the stored state is an active claim whose lock has expired.
     *
     * @param mixed $state
     */
    public function isExpiredActive(mixed $state): bool
    {
        if (!$this->isActive($state)) {
            return false;
        }

        $expiresAt = $state['expires_at'] ?? null;

        return is_numeric($expiresAt) && (int) $expiresAt < time();
    }

    /**
     * Whether the stored state is a finished claim.
     *
     * @param mixed $state
     */
    public function isComplete(mixed $state): bool
    {
        return is_array($state) && ($state['status'] ?? null) === 'complete';
    }

    /**
     * The post id recorded by a completed claim, when present.
     *
     * @param mixed $state
     */
    public function completedPostId(mixed $state): ?int
    {
        if (!is_array($state)) {
            return null;
        }

        $postId = $state['post_id'] ?? null;

        return is_numeric($postId) ? (int) $postId : null;
    }

    /**
     * The lock expiry of an active claim, when present.
     *
     * @param mixed $state
     */
    public function activeExpiresAt(mixed $state): int
    {
        if (is_array($state) && is_numeric($state['expires_at'] ?? null)) {
            return (int) $state['expires_at'];
        }

        return time();
    }

    /**
     * Attempt an atomic fresh claim. Only the first concurrent caller wins.
     */
    public function tryClaim(string $option, array $state): bool
    {
        return add_option($option, $state, '', 'no');
    }

    /**
     * Reclaim an expired claim by compare-and-swapping it with a fresh claim.
     *
     * The swap only succeeds when the row still stores byte-identical the
     * expired claim the caller observed. A stale contender whose observation
     * no longer matches never touches a fresh owner's claim and fails closed.
     */
    public function tryReclaim(string $option, array $freshState, mixed $expected): bool
    {
        $current = $this->read($option);

        if (!$this->isExpiredActive($current) || !$this->matchesExpectedState($current, $expected)) {
            return false;
        }

        if (!$this->casReplace($option, $current, $freshState)) {
            return false;
        }

        $this->trackClaim($option);

        return true;
    }

    /**
     * Adopt the durable saved resource of an expired claim.
     *
     * Only one caller can transition the observed expired active claim to a
     * completed, notified claim: the compare-and-swap replaces the exact
     * observed bytes with the completed state, so concurrent adopters
     * serialize on the row and exactly one of them wins. Every losing caller
     * returns false and must answer with a replay or the retryable
     * in-progress response WITHOUT firing the completion notification.
     *
     * Returns the adopting owner token, or false when the caller lost the
     * arbitration or the claim is no longer an expired active claim.
     *
     * @param mixed $expected The expired claim state the caller observed.
     */
    public function tryAdopt(string $option, int $postId, mixed $expected): string|false
    {
        $current = $this->read($option);

        if (!$this->isExpiredActive($current) || !$this->matchesExpectedState($current, $expected)) {
            return false;
        }

        $owner = $this->newOwner();

        $complete = [
            'status' => 'complete',
            'owner' => $owner,
            'post_id' => $postId,
            'notified' => true,
        ];

        if (!$this->casReplace($option, $current, $complete)) {
            return false;
        }

        return $owner;
    }

    /**
     * Complete a claim. Only the compare-and-swap winner may complete it.
     */
    public function tryComplete(string $option, string $owner, ?int $postId): bool
    {
        $state = $this->read($option);

        if (!$this->isOwnedActiveState($state, $owner)) {
            return false;
        }

        return $this->casReplace($option, $state, [
            'status' => 'complete',
            'owner' => $owner,
            'post_id' => $postId,
            'notified' => true,
        ]);
    }

    /**
     * Release a claim after a failed request so the key can be retried.
     *
     * Only the exact observed active claim of the current owner is deleted;
     * a caller whose claim was taken over in the meantime fails closed and
     * never deletes the new owner's claim.
     */
    public function tryRelease(string $option, string $owner): bool
    {
        $state = $this->read($option);

        if (!$this->isOwnedActiveState($state, $owner)) {
            return false;
        }

        return $this->casDelete($option, $state);
    }

    /**
     * Durably record the resource a claim created or targets.
     *
     * Written as a compare-and-swap as soon as the resource identity is known
     * (the native REST insert hook), so a crash after this point lets a retry
     * adopt the saved resource instead of creating a duplicate. A lost race
     * leaves the identity unrecorded and fails closed.
     */
    public function tryRecordSavedPost(string $option, string $owner, int $postId): void
    {
        $state = $this->read($option);

        if (!$this->isOwnedActiveState($state, $owner)) {
            return;
        }

        $replacement = $state;
        $replacement['saved_post_id'] = $postId;

        $this->casReplace($option, $state, $replacement);
    }

    /**
     * Add a claimed option to the bounded history and prune the oldest
     * entries.
     *
     * The history list itself is plain GC bookkeeping and is updated with
     * update_option(). The prune deletions are ownership-sensitive and run as
     * compare-and-swap deletes: a pruned claim is only removed when it still
     * stores exactly the observed finished or expired state; a claim that was
     * taken over in the meantime survives.
     */
    public function trackClaim(string $option): void
    {
        $history = get_option($this->historyOption);
        $history = is_array($history) ? array_values($history) : [];
        $history[] = $option;

        $pruned = [];

        if (count($history) > $this->historyLimit) {
            $pruned = array_slice($history, 0, count($history) - $this->historyLimit);
            $history = array_slice($history, -$this->historyLimit);
        }

        update_option($this->historyOption, $history, false);

        foreach ($pruned as $name) {
            if (!is_string($name)) {
                continue;
            }

            $state = $this->read($name);

            if (!is_array($state)) {
                continue;
            }

            if ($this->isComplete($state) || $this->isExpiredActive($state)) {
                $this->casDelete($name, $state);
            }
        }
    }

    /**
     * Whether the stored claim is exactly the expected expired claim.
     *
     * @param mixed $current
     * @param mixed $expected
     */
    private function matchesExpectedState(mixed $current, mixed $expected): bool
    {
        if (!is_array($current) || !is_array($expected)) {
            return false;
        }

        foreach (['status', 'owner', 'expires_at'] as $field) {
            if (($current[$field] ?? null) !== ($expected[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the state is an active claim owned by the given token.
     *
     * @param mixed $state
     */
    private function isOwnedActiveState(mixed $state, string $owner): bool
    {
        if (!$this->isActive($state)) {
            return false;
        }

        return ($state['owner'] ?? null) === $owner;
    }

    /**
     * Replace an option only when it still stores byte-identical the observed
     * value.
     *
     * Success requires exactly one affected row: zero rows means the stored
     * value changed under us (lost race) and false means a database error.
     * Both fail closed.
     */
    private function casReplace(string $option, array $expected, array $replacement): bool
    {
        $wpdb = $this->wpdb();

        if ($wpdb === null || !isset($wpdb->options)) {
            return false;
        }

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s",
            serialize($replacement),
            $option,
            serialize($expected)
        ));

        if ((int) $affected !== 1) {
            return false;
        }

        $this->invalidateOptionCaches($option);

        return true;
    }

    /**
     * Delete an option only when it still stores byte-identical the observed
     * value.
     *
     * Success requires exactly one affected row; zero rows (lost race) and
     * false (database error) both fail closed.
     */
    private function casDelete(string $option, array $expected): bool
    {
        $wpdb = $this->wpdb();

        if ($wpdb === null || !isset($wpdb->options)) {
            return false;
        }

        $affected = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = BINARY %s",
            $option,
            serialize($expected)
        ));

        if ((int) $affected !== 1) {
            return false;
        }

        $this->invalidateOptionCaches($option);

        return true;
    }

    /**
     * The wpdb instance, or null when unavailable (for example in standalone
     * unit tests). Every compare-and-swap primitive fails closed without it.
     */
    private function wpdb(): object|null
    {
        global $wpdb;

        return isset($wpdb) && is_object($wpdb) ? $wpdb : null;
    }

    /**
     * Invalidate the option caches after a direct database write, matching
     * what the WordPress options API would have invalidated.
     */
    private function invalidateOptionCaches(string $option): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }

        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
