<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use AcfService\AcfService;
use WP_Error;
use WP_Post;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WpService\WpService;

/** Prepare real image IDs before native schema validation, without changing that schema. */
final class CreateReceiver
{
    private array $contexts = [];

    public function __construct(private WpService $wpService, private AcfService $acfService) {}

    public function addHooks(): void
    {
        // ACF initializes its request-specific schema at priority 10.
        $this->wpService->addFilter('rest_pre_dispatch', [$this, 'prepare'], 20, 3);
        $this->wpService->addFilter('rest_request_after_callbacks', [$this, 'afterCallbacks'], PHP_INT_MAX, 3);
        $this->wpService->addFilter('acf/connect_attachment_to_post', [$this, 'preserveExistingParent'], 10, 3);
    }

    public function prepare(mixed $response, mixed $server, WP_REST_Request $request): mixed
    {
        $version = $request->get_header('X-ACF-Rest-Upload-Version');
        if ($response !== null || $version === null) { return $response; }
        if ($version !== '3') { return self::error('unsupported_version', 400, 'Only protocol version 3 is supported.'); }
        if ($request->get_method() !== 'POST' || ($request->get_content_type()['value'] ?? '') !== 'multipart/form-data') {
            return self::error('unsupported_request', 400, 'Use a multipart native collection create.');
        }
        $handler = null;
        foreach ($server->get_routes() as $route => $handlers) {
            if (!preg_match('@^' . $route . '$@i', $request->get_route())) { continue; }
            foreach ($handlers as $candidate) {
                if ($candidate['methods']['POST'] ?? false) { $handler = $candidate; break; }
            }
            if ($handler !== null) { break; }
        }
        $callback = $handler['callback'] ?? null;
        if (!is_array($callback) || !$callback[0] instanceof WP_REST_Posts_Controller || $callback[1] !== 'create_item'
            || (new \ReflectionMethod($callback[0], 'create_item'))->getDeclaringClass()->getName() !== WP_REST_Posts_Controller::class) {
            return self::error('unsupported_request', 400, 'The endpoint must retain native post creation.');
        }
        $type = null;
        foreach ($this->wpService->getPostTypes(['show_in_rest' => true], 'objects') as $postType) {
            if ($postType->get_rest_controller() === $callback[0]
                && strcasecmp($this->wpService->restGetRouteForPostTypeItems($postType->name), $request->get_route()) === 0) {
                $type = $postType->name;
                break;
            }
        }
        if ($type === null) { return self::error('unsupported_request', 400, 'Use the registered native collection.'); }
        $payload = (new CreatePayload())->decode($request);
        if ($payload instanceof WP_Error) { return $payload; }
        $controls = array_flip(['context', '_fields', '_embed', '_envelope', '_locale', '_pretty', 'acf_format']);
        $request->set_query_params(array_intersect_key($request->get_query_params(), $controls));
        $request->set_body_params(array_replace(array_intersect_key($request->get_body_params(), $controls), $payload['values']));
        $request->set_url_params([]);
        $request->set_default_params([]);
        $image = new CreateImage($this->wpService);
        $total = 0;
        foreach ($payload['references'] as $name => $key) {
            $field = $this->resolveField($type, (string) $name);
            if ($field === null || ($field['type'] ?? null) !== 'image' || ($handler['args']['acf']['properties'][$name] ?? null) === null) {
                return self::error('invalid_reference', 400, 'The reference must identify an exposed native image field.');
            }
            $error = $image->validate($payload['files'][$key], $field);
            if ($error !== null) { return $error; }
        }
        foreach ($payload['files'] as $file) { $total += $file['size']; }
        if ($total > 8_388_608) { return self::error('too_large', 413, 'Images exceed the aggregate limit.'); }

        // This preflight does not replace core validation. Only a clone's ordinary arguments
        // are sanitized for the early permission decision; core receives the unchanged full schema.
        $permissionRequest = clone $request;
        $ordinary = $handler;
        unset($ordinary['args']['acf']);
        $permissionRequest->set_attributes($ordinary);
        $valid = $permissionRequest->has_valid_params();
        if (!$valid instanceof WP_Error) { $valid = $permissionRequest->sanitize_params(); }
        if ($valid instanceof WP_Error) { return $valid; }
        $permission = ($handler['permission_callback'])($permissionRequest);
        if ($permission instanceof WP_Error) { return $permission; }
        if ($permission === false || $permission === null) { return self::error('forbidden', 403, 'Native creation was denied.'); }
        if ($payload['files'] !== [] && !$this->wpService->currentUserCan('upload_files')) {
            return self::error('forbidden', 403, 'You are not allowed to upload files.');
        }
        $id = spl_object_id($request);
        $this->contexts[$id] = ['request' => $request, 'type' => $type, 'post' => null,
            'attachments' => [], 'references' => $payload['references'], 'hook' => null];
        try {
            foreach ($payload['files'] as $key => $file) {
                $attachment = $image->sideload($file);
                if ($attachment instanceof WP_Error) { return $this->afterCallbacks($attachment, $handler, $request); }
                $this->contexts[$id]['attachments'][$key] = $attachment;
            }
            $acf = $request->get_param('acf');
            foreach ($payload['references'] as $name => $key) { $acf[$name] = $this->contexts[$id]['attachments'][$key]; }
            if ($payload['references'] !== []) { $request->set_param('acf', $acf); }
            $hook = function ($post, $actual, $creating) use ($request, $id): void {
                if ($actual !== $request || !$creating) { return; }
                $this->contexts[$id]['post'] = $post->ID;
                foreach ($this->contexts[$id]['attachments'] as $attachment) {
                    $this->wpService->wpUpdatePost(['ID' => $attachment, 'post_parent' => $post->ID], true);
                }
            };
            $this->contexts[$id]['hook'] = $hook;
            $this->wpService->addAction('rest_insert_' . $type, $hook, 10, 3);
        } catch (\Throwable) {
            return $this->afterCallbacks(self::error('storage_failed', 500, 'Could not prepare the create.'), $handler, $request);
        }
        // WordPress now performs all normal schema/ACF validation and sanitization with real IDs,
        // checks native permission again, and calls the original native create callback.
        return null;
    }

    public function preserveExistingParent(bool $connect, mixed $attachment, mixed $post): bool
    {
        foreach ($this->contexts as $context) {
            if ($context['post'] === (int) $post) {
                // Native ACF otherwise adopts unparented existing images during its save.
                return $connect && in_array((int) $attachment, $context['attachments'], true);
            }
        }
        return $connect;
    }

    private function resolveField(string $postType, string $name): ?array
    {
        $matches = [];
        foreach ($this->acfService->getFieldGroups(['post_type' => $postType]) as $group) {
            if (!($group['show_in_rest'] ?? false)) { continue; }
            $fields = array_filter($this->acfService->acfGetFields($group['key']), static function (array $field): bool {
                // AcfService 1.3.0 has no registered field-type lookup.
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

    public function afterCallbacks(mixed $response, mixed $handler, WP_REST_Request $request): mixed
    {
        $id = spl_object_id($request);
        $context = $this->contexts[$id] ?? null;
        if ($context === null) { return $response; }
        unset($this->contexts[$id]);
        if ($context['hook'] !== null) { $this->wpService->removeAction('rest_insert_' . $context['type'], $context['hook'], 10); }
        try {
            $failed = $response instanceof WP_Error || !$response instanceof WP_REST_Response || $response->get_status() !== 201;
            if (!$failed && !$this->checkComplete($context)) {
                $response = self::error('storage_failed', 500, 'Could not verify the saved create.');
                $failed = true;
            }
        } catch (\Throwable) {
            $response = self::error('storage_failed', 500, 'Could not verify the saved create.');
            $failed = true;
        }
        if ($failed) {
            return $this->cleanup($context) ? $response : self::error('cleanup_failed', 500, 'Could not remove the failed create.');
        }
        // Notification delivery is not part of the storage transaction.
        try {
            $this->wpService->doAction('ApiSponsorManager/uploadCreated', $context['post'], $request);
        } catch (\Throwable) {
            // Storage is already verified; mail failure must not roll it back.
            return $response;
        }
        return $response;
    }

    private function checkComplete(array $context): bool
    {
        if (!is_int($context['post']) || !$this->wpService->getPost($context['post']) instanceof WP_Post) { return false; }
        foreach ($context['attachments'] as $attachment) {
            $stored = $this->wpService->getPost($attachment);
            $file = $this->wpService->getAttachedFile($attachment);
            if (!$stored instanceof WP_Post || $stored->post_type !== 'attachment' || (int) $stored->post_parent !== $context['post']
                || !is_string($file) || !is_readable($file)) { return false; }
        }
        foreach ($context['references'] as $name => $key) {
            if ((int) $this->wpService->getPostMeta($context['post'], $name, true) !== $context['attachments'][$key]) { return false; }
        }
        return true;
    }

    private function cleanup(array $context): bool
    {
        $clean = true;
        foreach ($context['attachments'] as $attachment) {
            try {
                $file = $this->wpService->getAttachedFile($attachment);
                $this->wpService->wpDeleteAttachment($attachment, true);
                if ($this->wpService->getPost($attachment) !== null || (is_string($file) && is_file($file))) { $clean = false; }
            } catch (\Throwable) { $clean = false; }
        }
        if (is_int($context['post'])) {
            try {
                $this->wpService->wpDeletePost($context['post'], true);
                if ($this->wpService->getPost($context['post']) !== null) { $clean = false; }
            } catch (\Throwable) { $clean = false; }
        }
        return $clean;
    }

    public static function error(string $code, int $status, string $message): WP_Error
    {
        return new WP_Error('acf_rest_upload_' . $code, $message, ['status' => $status]);
    }
}
