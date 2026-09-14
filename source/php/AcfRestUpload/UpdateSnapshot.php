<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;
use WP_Post;

/** Request-local compensation for the supported multipart update fields. */
final class UpdateSnapshot
{
    private array $native = [];
    private array $fields = [];

    private function __construct(private readonly int $postId)
    {
    }

    /** The resolver must return the unique REST-exposed field for this post. */
    public static function capture(WP_Post $post, array $params, callable $resolve): self|WP_Error
    {
        $snapshot = new self((int) $post->ID);
        foreach (['title' => 'post_title', 'status' => 'post_status'] as $param => $property) {
            if (array_key_exists($param, $params)) {
                $snapshot->native[$property] = $post->{$property};
            }
        }
        foreach (($params['acf'] ?? []) as $name => $_value) {
            if (!function_exists('get_field') || !function_exists('update_field') || !function_exists('delete_field')) {
                return new WP_Error('acf_rest_upload_snapshot_failed', 'ACF update recovery is unavailable.', ['status' => 500]);
            }
            $field = is_string($name) ? $resolve($name) : null;
            if (!is_array($field) || !is_string($field['key'] ?? null) || !is_string($field['name'] ?? null)) {
                return new WP_Error('acf_rest_upload_unsupported_update', 'An ACF update field is unavailable or ambiguous.', ['status' => 400]);
            }
            $name = $field['name'];
            $snapshot->fields[] = [
                'name' => $name,
                'key' => $field['key'],
                'exists' => metadata_exists('post', $snapshot->postId, $name),
                'value' => get_field($field['key'], $snapshot->postId, false),
                'referenceExists' => metadata_exists('post', $snapshot->postId, '_' . $name),
                'reference' => get_post_meta($snapshot->postId, '_' . $name, true),
            ];
        }
        return $snapshot;
    }

    /** Read storage after each restoration. A false update result can mean no change. */
    public function restore(): bool
    {
        $restored = true;
        if ($this->native !== []) {
            wp_update_post(wp_slash(['ID' => $this->postId] + $this->native), true);
            $post = get_post($this->postId);
            foreach ($this->native as $property => $value) {
                if (!$post instanceof WP_Post || $post->{$property} !== $value) {
                    $restored = false;
                }
            }
        }
        foreach ($this->fields as $field) {
            if ($field['exists']) {
                update_field($field['key'], wp_slash($field['value']), $this->postId);
                // ACF interprets null as deletion. Preserve a present null separately.
                if ($field['value'] === null) {
                    update_post_meta($this->postId, $field['name'], null);
                    if (function_exists('acf_flush_value_cache')) {
                        acf_flush_value_cache($this->postId, $field['name']);
                    }
                }
            } else {
                delete_field($field['key'], $this->postId);
            }
            $reference = '_' . $field['name'];
            if ($field['referenceExists']) {
                update_post_meta($this->postId, $reference, wp_slash($field['reference']));
            } else {
                delete_post_meta($this->postId, $reference);
            }
            if (metadata_exists('post', $this->postId, $field['name']) !== $field['exists']
                || ($field['exists'] && get_field($field['key'], $this->postId, false) !== $field['value'])
                || metadata_exists('post', $this->postId, $reference) !== $field['referenceExists']
                || ($field['referenceExists'] && get_post_meta($this->postId, $reference, true) !== $field['reference'])) {
                $restored = false;
            }
        }
        return $restored;
    }
}
