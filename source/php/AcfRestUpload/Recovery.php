<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Post;
use WP_REST_Request;
use WpService\WpService;

/**
 * Minimal cleanup for one request's uploaded attachments.
 *
 * Capture is in memory for the duration of preparation only. A controlled preparation
 * failure marks the captured attachments for the hourly cleanup callback; nothing in the
 * request deletes storage, posts, or files.
 */
final class Recovery
{
    public const OWNER = '_sponsor_upload_owner';
    public const CLEANUP = '_sponsor_upload_cleanup';
    public const HOOK = 'sponsor_manager_recover_uploads';
    private const BATCH = 20;

    /** @var array<int, array{token: string, attachments: list<int>, hook: callable|null}> */
    private array $contexts = [];

    public function __construct(private WpService $wpService) {}

    public function begin(WP_REST_Request $request): bool
    {
        $id = spl_object_id($request);
        if (isset($this->contexts[$id])) { return false; }
        try { $token = bin2hex(random_bytes(16)); }
        catch (\Throwable) { return false; }
        $hook = function (mixed $metaId, int $post, string $key, mixed $value) use ($id): void {
            if ($key !== self::OWNER || !is_string($value) || !isset($this->contexts[$id])
                || $value !== $this->contexts[$id]['token']) { return; }
            $this->capture($id, $post);
        };
        $this->contexts[$id] = ['token' => $token, 'attachments' => [], 'hook' => $hook];
        $this->wpService->addFilter('added_post_meta', $hook, PHP_INT_MAX, 4);
        return true;
    }

    /** Native media_handle_sideload inserts meta_input before file metadata, so the token reaches the hook. */
    public function upload(WP_REST_Request $request, callable $store): mixed
    {
        $id = spl_object_id($request);
        if (!isset($this->contexts[$id])) { return $store([]); }
        $result = $store(['meta_input' => [self::OWNER => $this->contexts[$id]['token']]]);
        if (is_int($result) && $result > 0) { $this->capture($id, $result); }
        return $result;
    }

    /** Only a controlled preparation failure calls this. Success and native failures create no marker. */
    public function markFailed(WP_REST_Request $request): bool
    {
        $attachments = $this->contexts[spl_object_id($request)]['attachments'] ?? [];
        $marked = true;
        foreach ($attachments as $attachment) {
            try { $this->wpService->updatePostMeta($attachment, self::CLEANUP, '1'); }
            catch (\Throwable) { $marked = false; }
        }
        return $marked;
    }

    public function release(WP_REST_Request $request): void
    {
        $id = spl_object_id($request);
        $hook = $this->contexts[$id]['hook'] ?? null;
        if ($hook !== null) { $this->wpService->removeFilter('added_post_meta', $hook, PHP_INT_MAX); }
        unset($this->contexts[$id]);
    }

    /** Hourly callback: a random limited batch of marked, unparented attachments, re-checked before deletion. */
    public function cleanup(): void
    {
        $attachments = [];
        try {
            $attachments = $this->wpService->getPosts([
                'post_type' => 'attachment',
                'post_parent' => 0,
                'posts_per_page' => self::BATCH,
                'fields' => 'ids',
                'meta_key' => self::CLEANUP,
                'meta_value' => '1',
                // A stable order would let permanent refusals fill the head of every batch.
                'orderby' => 'rand',
                'no_found_rows' => true,
            ]);
        } catch (\Throwable) {
            return; // An unavailable query never grants deletion permission.
        }
        if (!is_array($attachments)) { return; }
        foreach ($attachments as $attachment) {
            $id = (int) $attachment;
            if ($id <= 0) { continue; }
            try {
                $stored = $this->wpService->getPost($id);
                if (!$stored instanceof WP_Post || (int) $stored->post_parent !== 0) { continue; }
                if ((string) $this->wpService->getPostMeta($id, self::CLEANUP, true) !== '1') { continue; }
                $this->wpService->wpDeleteAttachment($id, true);
            } catch (\Throwable) {
                continue; // A refused or failed deletion keeps its marker for a later run.
            }
        }
    }

    private function capture(int $id, int $attachment): void
    {
        if ($attachment <= 0 || !isset($this->contexts[$id])) { return; }
        if (!in_array($attachment, $this->contexts[$id]['attachments'], true)) {
            $this->contexts[$id]['attachments'][] = $attachment;
        }
    }
}
