<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Receives ACF REST uploads encoded with the multipart protocol v1.
 *
 * The client sends the regular ACF payload together with a protocol version
 * header, an idempotency key and the referenced binaries under the reserved
 * `_acf_rest_files[<key>]` part name. File references inside the payload use
 * the `$file:<key>` marker. Explicit nulls are listed in `_acf_rest_nulls[]`.
 *
 * The receiver collects the references, validates that the referenced keys and
 * the uploaded parts match, clears the markers before ACF validates the
 * payload, sideloads each referenced binary into the media library and finally
 * parents the created attachments to the post that the REST callback created.
 */
class Receiver implements Hookable
{
    /**
     * Supported protocol version.
     */
    private const PROTOCOL_VERSION = '1';

    /**
     * Header that marks a request as a protocol v1 multipart upload.
     */
    private const VERSION_HEADER = 'X-ACF-Rest-Upload-Version';

    /**
     * Header that carries the client generated idempotency key.
     */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * Seconds an active idempotency claim stays locked before it may be reclaimed.
     *
     * The lock is only held while a request is being processed. If the process
     * is interrupted before it can release or complete the claim, the stale
     * lock expires after this window so a retry can proceed.
     */
    private const ACTIVE_LOCK_TTL = 900;

    /**
     * Reserved multipart part that carries the referenced binaries.
     */
    private const FILES_FIELD = '_acf_rest_files';

    /**
     * Reserved multipart part that lists explicitly requested null paths.
     */
    private const NULLS_FIELD = '_acf_rest_nulls';

    /**
     * Request parameter that carries the ACF payload.
     */
    private const ACF_PARAM = 'acf';

    /**
     * Routes that accept multipart uploads.
     *
     * @var list<string>
     */
    private const ROUTE_PREFIXES = [
        '/wp/v2/sponsor-assignments',
        '/wp/v2/sponsor-offerings',
    ];

    /**
     * Methods that accept multipart uploads.
     *
     * @var list<string>
     */
    private const METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * ACF field types that may carry a multipart file reference.
     *
     * @var list<string>
     */
    private const UPLOAD_FIELD_TYPES = ['image', 'file', 'gallery'];

    /**
     * Request scoped state keyed by `spl_object_id()`.
     *
     * @var array<int, array{
     *     references: list<FileReference>,
     *     files: array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}>,
     *     attachments: list<int>,
     *     idempotencyOption: string|null
     * }>
     */
    private array $contexts = [];

    /**
     * Register the REST lifecycle hooks.
     */
    public function addHooks(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'preDispatch'], 1, 3);
        add_filter('rest_request_before_callbacks', [$this, 'beforeCallbacks'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'postDispatch'], 10, 3);
    }

    /**
     * Validate and decode the multipart upload before the route is dispatched.
     *
     * @param mixed $response
     * @param mixed $server
     */
    public function preDispatch($response, $server, WP_REST_Request $request): mixed
    {
        $version = $request->get_header(self::VERSION_HEADER);

        if ($version === null || $version === '') {
            // Not a multipart upload request.
            return $response;
        }

        if ((string) $version !== self::PROTOCOL_VERSION) {
            return new WP_Error(
                'acf_rest_upload_unsupported_version',
                __('Unsupported ACF REST upload protocol version.', 'api-sponsor-manager'),
                ['status' => 400]
            );
        }

        if (!$this->isSupportedRequest($request)) {
            return new WP_Error(
                'acf_rest_upload_unsupported_request',
                __('ACF REST uploads are not supported for this route or method.', 'api-sponsor-manager'),
                ['status' => 400]
            );
        }

        if (!current_user_can('upload_files')) {
            return new WP_Error(
                'acf_rest_upload_forbidden',
                __('You are not allowed to upload files.', 'api-sponsor-manager'),
                ['status' => 403]
            );
        }

        $idempotencyError = $this->validateIdempotencyKey($request);

        if ($idempotencyError !== null) {
            return $idempotencyError;
        }

        $acf = $request->get_param(self::ACF_PARAM);
        $acf = is_array($acf) ? $acf : [];

        $references = (new FileReferenceCollector())->collect($acf);
        $files = $this->flattenUploadedFiles($request->get_file_params());

        $referenceError = $this->validateReferences($references);

        if ($referenceError !== null) {
            return $referenceError;
        }

        try {
            (new PartKeyValidator())->validate($references, array_keys($files));
        } catch (InvalidRequestException $exception) {
            return new WP_Error(
                InvalidRequestException::CODE,
                $exception->getMessage(),
                ['status' => 400]
            );
        }

        $referencePaths = array_map(
            static fn(FileReference $reference): string => $reference->path,
            $references
        );

        try {
            // Clear the markers and restore explicit nulls before ACF validates
            // the payload.
            $acf = (new NullInjector())->inject(
                $acf,
                [...$referencePaths, ...$this->extractNullPaths($request)]
            );
        } catch (InvalidArgumentException $exception) {
            return new WP_Error(
                InvalidRequestException::CODE,
                $exception->getMessage(),
                ['status' => 400]
            );
        }

        $idempotencyResponse = $this->registerIdempotency($request);

        if ($idempotencyResponse !== null) {
            return $idempotencyResponse;
        }

        $request->set_param(self::ACF_PARAM, $acf);

        $this->contexts[spl_object_id($request)] = [
            'references' => $references,
            'files' => $files,
            'attachments' => [],
            'idempotencyOption' => $this->idempotencyOptionName($request),
        ];

        return $response;
    }

    /**
     * Sideload the referenced binaries and replace the markers with IDs.
     *
     * @param mixed $response
     * @param mixed $handler
     */
    public function beforeCallbacks($response, $handler, WP_REST_Request $request): mixed
    {
        $contextId = spl_object_id($request);

        if (!isset($this->contexts[$contextId])) {
            return $response;
        }

        $permissionCallback = is_array($handler) ? ($handler['permission_callback'] ?? null) : null;

        if (is_callable($permissionCallback)) {
            $permission = call_user_func($permissionCallback, $request);

            if (is_wp_error($permission)) {
                return $permission;
            }

            if ($permission === false || $permission === null) {
                return new WP_Error(
                    'rest_forbidden',
                    __('Sorry, you are not allowed to do that.', 'api-sponsor-manager'),
                    ['status' => rest_authorization_required_code()]
                );
            }
        }

        $references = $this->contexts[$contextId]['references'];
        $files = $this->contexts[$contextId]['files'];

        $pathsByKey = [];
        foreach ($references as $reference) {
            $pathsByKey[$reference->key][] = $reference->path;
        }

        $acf = $request->get_param(self::ACF_PARAM);
        $acf = is_array($acf) ? $acf : [];

        $this->loadMediaFunctions();

        foreach ($pathsByKey as $key => $paths) {
            $file = $files[$key] ?? null;

            if (!is_array($file)) {
                continue;
            }

            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);

            if ($error !== UPLOAD_ERR_OK || $tmpName === '' || $size <= 0) {
                $this->rollback($contextId);

                return new WP_Error(
                    'acf_rest_upload_invalid_file',
                    __('An uploaded file part is invalid.', 'api-sponsor-manager'),
                    ['status' => 400]
                );
            }

            $attachmentId = media_handle_sideload([
                'name' => (string) ($file['name'] ?? ''),
                'type' => (string) ($file['type'] ?? ''),
                'tmp_name' => $tmpName,
                'error' => $error,
                'size' => $size,
            ], 0);

            if (is_wp_error($attachmentId)) {
                $this->rollback($contextId);

                return $attachmentId;
            }

            $this->contexts[$contextId]['attachments'][] = (int) $attachmentId;

            foreach ($paths as $path) {
                $acf = $this->setValueAtPath($acf, BracketPath::parse($path), (int) $attachmentId);
            }
        }

        $request->set_param(self::ACF_PARAM, $acf);

        return $response;
    }

    /**
     * Roll back on failure and parent the attachments on success.
     *
     * @param mixed $response
     * @param mixed $server
     */
    public function postDispatch($response, $server, WP_REST_Request $request): mixed
    {
        $contextId = spl_object_id($request);

        if (!isset($this->contexts[$contextId])) {
            return $response;
        }

        $context = $this->contexts[$contextId];
        unset($this->contexts[$contextId]);

        if ($this->isErrorResponse($response)) {
            foreach ($context['attachments'] as $attachmentId) {
                wp_delete_attachment($attachmentId, true);
            }

            $this->clearIdempotency($context['idempotencyOption']);

            return $response;
        }

        $postId = $this->resolveResponsePostId($response);

        if ($postId !== null && $postId > 0) {
            foreach ($context['attachments'] as $attachmentId) {
                wp_update_post([
                    'ID' => $attachmentId,
                    'post_parent' => $postId,
                ]);
            }
        }

        $this->completeIdempotency($context['idempotencyOption'], $postId);

        return $response;
    }

    /**
     * Validate that every file reference targets an enabled top-level ACF field.
     *
     * A reference is only accepted when its path is exactly a top-level field
     * name, or, for gallery fields, the field name followed by a single integer
     * index. That field must be an image/file/gallery field with the
     * `allow_multipart_rest_upload` setting enabled, and the field group that
     * contains it must be exposed through the REST API. Empty, nested,
     * unrecognized and disabled references are rejected.
     *
     * @param list<FileReference> $references
     */
    private function validateReferences(array $references): ?WP_Error
    {
        if ($references === []) {
            return null;
        }

        if (!function_exists('acf_get_field') || !function_exists('acf_get_field_group')) {
            return $this->invalidReferenceError();
        }

        foreach ($references as $reference) {
            if ($reference->path === '') {
                return $this->invalidReferenceError();
            }

            try {
                $segments = BracketPath::parse($reference->path);
            } catch (InvalidArgumentException $exception) {
                return $this->invalidReferenceError();
            }

            // The marker must always start at a top-level field name.
            $fieldName = $segments[0] ?? null;

            if (!is_string($fieldName) || $fieldName === '') {
                return $this->invalidReferenceError();
            }

            $field = acf_get_field($fieldName);

            if (!is_array($field)) {
                return $this->invalidReferenceError();
            }

            $fieldType = $field['type'] ?? null;

            if (!in_array($fieldType, self::UPLOAD_FIELD_TYPES, true)) {
                return $this->invalidReferenceError();
            }

            if (!$this->isSupportedReferencePath((string) $fieldType, $fieldName, $reference->path, $segments)) {
                return $this->invalidReferenceError();
            }

            if (empty($field[FieldSettings::SETTING_NAME])) {
                return $this->invalidReferenceError();
            }

            $group = acf_get_field_group($field['parent'] ?? '');

            if (!is_array($group) || empty($group['show_in_rest'])) {
                return $this->invalidReferenceError();
            }
        }

        return null;
    }

    /**
     * Whether a reference path uses the canonical shape for the field type.
     *
     * Image and file fields accept exactly the top-level field name. Gallery
     * fields accept the field name followed by a single integer index, which
     * keeps the gallery order intact when the value is written at that path.
     * Any other nested path is unsupported.
     *
     * @param string           $fieldType
     * @param string           $fieldName
     * @param string           $path
     * @param list<int|string> $segments
     */
    private function isSupportedReferencePath(string $fieldType, string $fieldName, string $path, array $segments): bool
    {
        if ($segments === [] || $segments[0] !== $fieldName) {
            return false;
        }

        if (BracketPath::format($segments) !== $path) {
            return false;
        }

        if ($fieldType === 'gallery') {
            return count($segments) === 2 && is_int($segments[1]);
        }

        return count($segments) === 1;
    }

    /**
     * Build the client error returned for an unusable file reference.
     */
    private function invalidReferenceError(): WP_Error
    {
        return new WP_Error(
            'acf_rest_upload_invalid_reference',
            __('The uploaded file is not attached to an upload enabled ACF field.', 'api-sponsor-manager'),
            ['status' => 400]
        );
    }

    /**
     * Whether the request targets a supported route and method.
     */
    private function isSupportedRequest(WP_REST_Request $request): bool
    {
        $route = (string) $request->get_route();

        $routeMatches = false;
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if ($route === $prefix) {
                $routeMatches = true;
                break;
            }

            if (str_starts_with($route, $prefix . '/')) {
                $suffix = substr($route, strlen($prefix) + 1);

                if ($suffix !== '' && ctype_digit($suffix)) {
                    $routeMatches = true;
                    break;
                }
            }
        }

        if (!$routeMatches) {
            return false;
        }

        return in_array(strtoupper((string) $request->get_method()), self::METHODS, true);
    }

    /**
     * Read the explicitly requested null paths from the reserved part.
     *
     * @return list<string>
     */
    private function extractNullPaths(WP_REST_Request $request): array
    {
        $nulls = $request->get_param(self::NULLS_FIELD);

        if (!is_array($nulls)) {
            return [];
        }

        $paths = [];
        foreach ($nulls as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Flatten the uploaded file parts into a map keyed by file reference key.
     *
     * @param array<array-key, mixed> $fileParams
     *
     * @return array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}>
     */
    private function flattenUploadedFiles(array $fileParams): array
    {
        $files = $fileParams[self::FILES_FIELD] ?? null;

        if (!is_array($files)) {
            return [];
        }

        // Native PHP $_FILES layout: parallel attribute arrays.
        if (isset($files['name']) && is_array($files['name']) && array_key_exists('tmp_name', $files)) {
            $records = [];
            $this->flattenNativeFiles($files['name'], $files, [], $records);

            return $records;
        }

        // Already normalized file map keyed by file key.
        $records = [];
        $this->flattenFileRecords($files, '', $records);

        return $records;
    }

    /**
     * @param mixed                                             $names
     * @param array<array-key, mixed>                           $files
     * @param array<int, int|string>                            $path
     * @param array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}> $records
     */
    private function flattenNativeFiles(mixed $names, array $files, array $path, array &$records): void
    {
        if (is_array($names)) {
            foreach ($names as $key => $child) {
                $this->flattenNativeFiles($child, $files, [...$path, $key], $records);
            }

            return;
        }

        if ($path === [] || !is_string($names) || $names === '') {
            return;
        }

        $key = (string) end($path);

        $records[$key] = [
            'name' => $names,
            'type' => (string) $this->pluckAttribute($files, 'type', $path, ''),
            'tmp_name' => (string) $this->pluckAttribute($files, 'tmp_name', $path, ''),
            'error' => (int) $this->pluckAttribute($files, 'error', $path, UPLOAD_ERR_NO_FILE),
            'size' => (int) $this->pluckAttribute($files, 'size', $path, 0),
        ];
    }

    /**
     * @param array<array-key, mixed> $files
     * @param array<int, int|string>  $path
     */
    private function pluckAttribute(array $files, string $attribute, array $path, mixed $default = null): mixed
    {
        $value = $files[$attribute] ?? null;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param mixed $node
     * @param array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}> $records
     */
    private function flattenFileRecords(mixed $node, string $path, array &$records): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['name']) && is_string($node['name']) && $path !== '') {
            $records[$path] = [
                'name' => $node['name'],
                'type' => isset($node['type']) && is_string($node['type']) ? $node['type'] : '',
                'tmp_name' => isset($node['tmp_name']) && is_string($node['tmp_name']) ? $node['tmp_name'] : '',
                'error' => isset($node['error']) ? (int) $node['error'] : UPLOAD_ERR_NO_FILE,
                'size' => isset($node['size']) ? (int) $node['size'] : 0,
            ];

            return;
        }

        foreach ($node as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '[' . $key . ']';
            $this->flattenFileRecords($child, $childPath, $records);
        }
    }

    /**
     * Write a value at a bracket path inside the ACF payload.
     *
     * @param array<mixed>           $values
     * @param non-empty-list<int|string> $segments
     *
     * @return array<mixed>
     */
    private function setValueAtPath(array $values, array $segments, int $value): array
    {
        if ($segments === []) {
            return $values;
        }

        $last = array_pop($segments);
        $node = &$values;

        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node[$last] = $value;

        return $values;
    }

    /**
     * Delete the attachments that were created for the request.
     */
    private function rollback(int $contextId): void
    {
        foreach ($this->contexts[$contextId]['attachments'] as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }

        $this->contexts[$contextId]['attachments'] = [];
    }

    /**
     * Whether the REST response represents a failure.
     */
    private function isErrorResponse(mixed $response): bool
    {
        if (is_wp_error($response)) {
            return true;
        }

        return $response instanceof WP_REST_Response && $response->get_status() >= 400;
    }

    /**
     * Extract the created post ID from a successful REST response.
     */
    private function resolveResponsePostId(mixed $response): ?int
    {
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
        } elseif (is_array($response)) {
            $data = $response;
        } else {
            return null;
        }

        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            return (int) $data['id'];
        }

        return null;
    }

    /**
     * Validate the client generated idempotency key.
     */
    private function validateIdempotencyKey(WP_REST_Request $request): ?WP_Error
    {
        $header = $request->get_header(self::IDEMPOTENCY_HEADER);

        if (!is_string($header) || trim($header) === '') {
            return new WP_Error(
                'acf_rest_upload_missing_idempotency_key',
                __('The Idempotency-Key header is required for ACF REST uploads.', 'api-sponsor-manager'),
                ['status' => 400]
            );
        }

        if (!$this->isValidIdempotencyKey(trim($header))) {
            return new WP_Error(
                'acf_rest_upload_invalid_idempotency_key',
                __('The Idempotency-Key header must be a UUID.', 'api-sponsor-manager'),
                ['status' => 400]
            );
        }

        return null;
    }

    /**
     * Whether the value looks like a canonical UUID idempotency key.
     */
    private function isValidIdempotencyKey(string $key): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $key
        ) === 1;
    }

    /**
     * Read the trimmed idempotency UUID from the request.
     */
    private function resolveIdempotencyKey(WP_REST_Request $request): ?string
    {
        $key = $request->get_header(self::IDEMPOTENCY_HEADER);

        if (!is_string($key)) {
            return null;
        }

        $key = trim($key);

        if (!$this->isValidIdempotencyKey($key)) {
            return null;
        }

        return $key;
    }

    /**
     * Build the option name that scopes the idempotency state.
     *
     * The scope combines the target route, the HTTP method, the current user
     * and the submitted UUID so that equal UUIDs used against different
     * resources or users never collide. Hashing keeps the option name within
     * the option_name column limit.
     */
    private function idempotencyOptionName(WP_REST_Request $request): ?string
    {
        $key = $this->resolveIdempotencyKey($request);

        if ($key === null) {
            return null;
        }

        $scope = implode('|', [
            (string) $request->get_route(),
            strtoupper((string) $request->get_method()),
            (string) get_current_user_id(),
            strtolower($key),
        ]);

        return 'acf_rest_upload_' . hash('sha256', $scope);
    }

    /**
     * Atomically claim the idempotency key or replay an earlier result.
     *
     * add_option() reports false when the option already exists, which makes it
     * the atomic claim: only the first concurrent caller wins the insert. An
     * existing active claim becomes a conflict, an existing completed claim
     * replays the created id. Active claims carry an expiry so a lock left
     * behind by an interrupted request can be reclaimed after the TTL.
     */
    private function registerIdempotency(WP_REST_Request $request): WP_Error|WP_REST_Response|null
    {
        $option = $this->idempotencyOptionName($request);

        if ($option === null) {
            return null;
        }

        if (add_option($option, $this->activeIdempotencyState(), '', 'no')) {
            return null;
        }

        $state = get_option($option);

        if (is_array($state) && ($state['status'] ?? null) === 'complete') {
            return new WP_REST_Response(
                ['id' => $state['id'] ?? null],
                200
            );
        }

        if ($this->isExpiredActiveState($state)) {
            delete_option($option);

            if (add_option($option, $this->activeIdempotencyState(), '', 'no')) {
                return null;
            }
        }

        return new WP_Error(
            'acf_rest_upload_in_progress',
            __('A request with this idempotency key is already being processed.', 'api-sponsor-manager'),
            ['status' => 409]
        );
    }

    /**
     * Build the state stored while a request holds an idempotency claim.
     *
     * @return array{status: string, expires_at: int}
     */
    private function activeIdempotencyState(): array
    {
        return [
            'status' => 'active',
            'expires_at' => time() + self::ACTIVE_LOCK_TTL,
        ];
    }

    /**
     * Whether a stored state is an active claim whose lock has expired.
     *
     * @param mixed $state
     */
    private function isExpiredActiveState(mixed $state): bool
    {
        if (!is_array($state) || ($state['status'] ?? null) !== 'active') {
            return false;
        }

        $expiresAt = $state['expires_at'] ?? null;

        return is_numeric($expiresAt) && (int) $expiresAt < time();
    }

    /**
     * Mark a finished request so retries can be recognized.
     */
    private function completeIdempotency(?string $option, ?int $postId): void
    {
        if ($option === null) {
            return;
        }

        update_option($option, ['status' => 'complete', 'id' => $postId], 'no');
    }

    /**
     * Release the idempotency key after a failed request so it can be retried.
     */
    private function clearIdempotency(?string $option): void
    {
        if ($option === null) {
            return;
        }

        delete_option($option);
    }

    /**
     * Load the admin media functions required by media_handle_sideload().
     */
    private function loadMediaFunctions(): void
    {
        if (function_exists('media_handle_sideload')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}
