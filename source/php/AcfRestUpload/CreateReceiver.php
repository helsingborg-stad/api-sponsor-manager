<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use AcfService\AcfService;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WpService\WpService;

/** Prepare real image IDs before native schema validation, without changing that schema. */
final class CreateReceiver
{
    private Recovery $recovery;

    public function __construct(private WpService $wpService, private AcfService $acfService, ?Recovery $recovery = null)
    {
        $this->recovery = $recovery ?? new Recovery($wpService);
    }

    public function addHooks(): void
    {
        // ACF initializes its request-specific schema at priority 10.
        $this->wpService->addFilter('rest_pre_dispatch', [$this, 'prepare'], 20, 3);
    }

    public function prepare(mixed $response, mixed $server, WP_REST_Request $request): mixed
    {
        $version = $request->get_header('X-ACF-Rest-Upload-Version');
        if ($response !== null || $version === null) { return $response; }
        if ($version !== '4') { return self::error('unsupported_version', 400, 'Only protocol version 4 is supported.'); }
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
        foreach ($payload['files'] as $name => $file) {
            $field = $this->resolveField($type, (string) $name);
            if ($field === null || ($field['type'] ?? null) !== 'image' || ($handler['args']['acf']['properties'][$name] ?? null) === null) {
                return self::error('invalid_reference', 400, 'The upload must identify an exposed native image field.');
            }
            $error = $image->validate($file, $field);
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
        if (!$this->recovery->begin($request)) {
            return self::error('storage_failed', 500, 'Could not establish safe storage.');
        }
        $attachments = [];
        try {
            foreach ($payload['files'] as $name => $file) {
                $attachment = $this->recovery->upload($request, fn ($data) => $image->sideload($file, $data));
                if ($attachment instanceof WP_Error) {
                    $this->recovery->markFailed($request);
                    return $attachment;
                }
                $attachments[$name] = $attachment;
            }
        } catch (\Throwable) {
            $this->recovery->markFailed($request);
            return self::error('storage_failed', 500, 'Could not prepare the create.');
        } finally {
            $this->recovery->release($request);
        }
        $acf = $request->get_param('acf');
        if (!is_array($acf)) { $acf = []; }
        foreach ($attachments as $name => $attachment) { $acf[$name] = $attachment; }
        if ($attachments !== []) {
            $request->set_param('acf', $acf);
            $request->set_file_params([]);
        }
        // WordPress now performs all normal schema/ACF validation and sanitization with real IDs,
        // checks native permission again, and calls the original native create callback.
        return null;
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

    public static function error(string $code, int $status, string $message): WP_Error
    {
        return new WP_Error('acf_rest_upload_' . $code, $message, ['status' => $status]);
    }
}
