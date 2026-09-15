<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use AcfService\AcfService;
use WP_Error;
use WP_REST_Request;
use WpService\WpService;

/** Native collection creates only. No operation store or completion notifications. */
final class CreateReceiver
{
    private array $contexts = [];

    public function __construct(private WpService $wpService, private AcfService $acfService) {}

    public function addHooks(): void
    {
        // ACF initializes at priority 10 and does not preserve earlier filter responses.
        $this->wpService->addFilter('rest_pre_dispatch', [$this, 'prepare'], 20, 3);
        $this->wpService->addFilter('rest_request_before_callbacks', [$this, 'beforeCallbacks'], 10, 3);
        $this->wpService->addFilter('rest_dispatch_request', [$this, 'upload'], 10, 4);
        $this->wpService->addFilter('rest_request_after_callbacks', [$this, 'afterCallbacks'], PHP_INT_MAX, 3);
    }

    public function prepare(mixed $response, mixed $server, WP_REST_Request $request): mixed
    {
        if ($response !== null || $request->get_header('X-ACF-Rest-Upload-Version') !== '2') {
            return $response;
        }
        foreach ($this->wpService->applyFilters('AcfRestUpload/destinations', []) as $route => $destination) {
            if ($request->get_route() === $route || str_starts_with($request->get_route(), $route . '/')) {
                if ($request->get_method() !== 'POST' || $request->get_route() !== $route) {
                    return self::error('unsupported_request', 400, 'Only collection POST creates accept image uploads.');
                }
                return $this->prepareImage($request, $destination);
            }
        }
        return $response;
    }

    private function prepareImage(WP_REST_Request $request, array $destination): ?WP_Error
    {
        $params = $request->get_params();
        if (strlen(http_build_query($params)) > 1_048_576) {
            return self::error('too_large', 413, 'Request parameters exceed 1 MiB.');
        }
        $name = $destination['image_field'];
        $references = [];
        $scan = static function (array $values, array $path = []) use (&$scan, &$references): bool {
            foreach ($values as $key => $value) {
                if (str_starts_with((string) $key, '_acf_rest_')
                    || in_array($key, ['_acf_field_key_map', '_acf_field_group_scope', '_acf_admin_mode'], true)) {
                    return false;
                }
                $next = [...$path, $key];
                if (is_array($value) && !$scan($value, $next)) { return false; }
                if (is_string($value) && str_starts_with($value, '$file:')) {
                    $references[] = ['path' => $next, 'key' => substr($value, 6)];
                }
            }
            return true;
        };
        if (!$scan($params) || count($references) > 1) {
            return self::error('invalid_request', 400, 'Reserved markers and multiple image references are not supported.');
        }
        foreach ([$request->get_query_params(), $request->get_json_params() ?? []] as $other) {
            if (array_intersect_key($other, $request->get_body_params()) || isset($other['acf'])) {
                return self::error('conflicting_parts', 400, 'Image parameters must use the multipart body only.');
            }
        }
        $files = $request->get_file_params();
        if ($references === []) {
            return $files === [] ? null : self::error('invalid_parts', 400, 'Unexpected binary part.');
        }
        $reference = $references[0];
        if ($reference['path'] !== ['acf', $name] || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $reference['key'])) {
            return self::error('invalid_reference', 400, 'Only the destination top-level image can reference a binary part.');
        }
        $key = $reference['key'];
        $part = $files['_acf_rest_files'] ?? [];
        $attributes = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (!is_array($part) || array_keys($files) !== ['_acf_rest_files']
            || array_diff(array_keys($part), [...$attributes, 'full_path'])) {
            return self::error('invalid_parts', 400, 'Supply exactly one image binary part.');
        }
        // PHP 8.1+ includes full_path. Validate its shape but never use it as a storage path.
        if (array_key_exists('full_path', $part)) { $attributes[] = 'full_path'; }
        $file = [];
        foreach ($attributes as $attribute) {
            if (!is_array($part[$attribute] ?? null) || array_map('strval', array_keys($part[$attribute])) !== [$key]
                || !is_scalar($part[$attribute][$key])) {
                return self::error('invalid_parts', 400, 'The image binary part is missing or malformed.');
            }
            $file[$attribute] = $part[$attribute][$key];
        }
        if (!is_int($file['error']) || !is_int($file['size']) || !is_string($file['name'])
            || !is_string($file['type']) || !is_string($file['tmp_name'])) {
            return self::error('invalid_parts', 400, 'The image binary part has invalid attributes.');
        }
        $field = $this->resolveField($destination['post_type'], $name);
        if ($field === null || ($field['type'] ?? null) !== 'image' || !($field['allow_multipart_rest_upload'] ?? false)) {
            return self::error('invalid_reference', 400, 'The destination image does not permit REST uploads.');
        }
        if (array_key_exists($field['key'], $params['acf'])) {
            return self::error('conflicting_parts', 400, 'The image cannot use both its name and field key.');
        }
        $image = new CreateImage($this->wpService);
        $error = $image->validate($file, $field, $request);
        if ($error !== null) { return $error; }
        $acf = $request->get_param('acf');
        $acf[$name] = null;
        $request->set_param('acf', $acf);
        $this->contexts[spl_object_id($request)] = [
            'request' => $request, 'file' => $file, 'name' => $name, 'type' => $destination['post_type'],
            'image' => $image, 'attachment' => null, 'hook' => null, 'error' => null,
        ];
        return null;
    }

    private function resolveField(string $postType, string $name): ?array
    {
        $matches = [];
        foreach ($this->acfService->getFieldGroups(['post_type' => $postType]) as $group) {
            if (!($group['show_in_rest'] ?? false)) { continue; }
            $fields = array_filter($this->acfService->acfGetFields($group['key']), static function (array $field): bool {
                // Approved exception: AcfService 1.3 has no registered field-type lookup.
                return (bool) (acf_get_field_type($field['type'])->show_in_rest ?? false);
            });
            $fields = $this->wpService->applyFilters('acf/rest/get_fields', $fields,
                ['type' => 'post', 'sub_type' => $postType, 'id' => null], 'POST');
            foreach ($fields as $field) {
                $parent = $field['parent'] ?? null;
                if (($field['name'] ?? null) === $name && ($parent === $group['key']
                    || (($group['ID'] ?? 0) > 0 && $parent === $group['ID']))) {
                    $matches[] = $field;
                }
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function beforeCallbacks(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        $context = $this->contexts[spl_object_id($request)] ?? null;
        if ($context === null || !$response instanceof WP_Error) { return $response; }
        $data = $response->get_error_data('rest_invalid_param');
        $detail = $data['details']['acf'] ?? [];
        // Defer only this reference's null placeholder, not arbitrary native errors.
        if ($response->get_error_codes() !== ['rest_invalid_param'] || array_keys($data['params'] ?? []) !== ['acf']
            || ($detail['code'] ?? null) !== 'rest_invalid_param'
            || ($detail['data']['param'] ?? null) !== 'acf[' . $context['name'] . ']'
            || ($detail['data']['value'] ?? null) !== 0) {
            return $response;
        }
        // Core skipped sanitization after validation failed. Permissions need ordinary values sanitized.
        $args = $request->get_attributes()['args'];
        unset($args['acf']);
        $result = $this->sanitizeSubset($request, $args);
        return $result instanceof WP_Error ? $result : null;
    }

    private function sanitizeSubset(WP_REST_Request $request, array $args): mixed
    {
        $attributes = $request->get_attributes();
        $request->set_attributes(array_replace($attributes, ['args' => $args]));
        try {
            return $request->sanitize_params();
        } finally {
            $request->set_attributes($attributes);
        }
    }

    public function upload(mixed $response, WP_REST_Request $request, mixed $route, mixed $handler): mixed
    {
        $id = spl_object_id($request);
        if ($response !== null || !isset($this->contexts[$id])) { return $response; }
        $context = &$this->contexts[$id];
        // Core already ran the native endpoint permission callback.
        if (!$this->wpService->currentUserCan('upload_files')) {
            return self::error('forbidden', 403, 'You are not allowed to upload files.');
        }
        $attachment = $context['image']->sideload($context['file']);
        if ($attachment instanceof WP_Error) { return $attachment; }
        $context['attachment'] = $attachment;
        $acf = $request->get_param('acf');
        $acf[$context['name']] = $attachment;
        $request->set_param('acf', $acf);
        $result = $request->has_valid_params();
        if (!$result instanceof WP_Error) {
            $result = $this->sanitizeSubset($request, array_intersect_key($request->get_attributes()['args'], ['acf' => true]));
        }
        if ($result instanceof WP_Error) {
            return $this->wpService->wpDeleteAttachment($attachment, true) ? $result
                : self::error('cleanup_failed', 500, 'Could not delete the rejected image.');
        }
        $context['hook'] = function ($post, $actual, $creating) use ($request, $id, $attachment): void {
            if ($actual !== $request || !$creating) { return; }
            $result = $this->wpService->wpUpdatePost(['ID' => $attachment, 'post_parent' => $post->ID], true);
            if ($result instanceof WP_Error || !$result) {
                $this->contexts[$id]['error'] = self::error('storage_failed', 500, 'Could not parent the uploaded image.');
            }
        };
        $this->wpService->addAction('rest_insert_' . $context['type'], $context['hook'], 10, 3);
        return null;
    }

    public function afterCallbacks(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        $id = spl_object_id($request);
        $context = $this->contexts[$id] ?? null;
        if ($context !== null) {
            if ($context['hook'] !== null) {
                $this->wpService->removeAction('rest_insert_' . $context['type'], $context['hook'], 10);
            }
            unset($this->contexts[$id]);
        }
        // Late native failures and complete-success notifications belong to the finalization slice.
        return $context['error'] ?? $response;
    }

    public static function error(string $code, int $status, string $message): WP_Error
    {
        return new WP_Error('acf_rest_upload_' . $code, $message, ['status' => $status]);
    }
}
