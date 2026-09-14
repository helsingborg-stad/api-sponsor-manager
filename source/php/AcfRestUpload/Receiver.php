<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use InvalidArgumentException;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Receives ACF REST uploads encoded with the multipart protocol v1.
 *
 * The client sends the regular ACF payload together with a protocol version
 * header, an idempotency key and the referenced binaries under the reserved
 * `_acf_rest_files[<key>]` part name. File references inside the payload use
 * the `$file:<key>` marker with numeric-index bracket paths
 * (`acf[gallery][1]`). Explicit nulls are listed in `_acf_rest_nulls[]` and
 * explicit empty lists in `_acf_rest_empty[]`; both use bracket paths rooted
 * at `acf`.
 *
 * Lifecycle:
 *  1. `rest_pre_dispatch` validates the protocol request, hardens the
 *     multipart input, resolves the referenced ACF fields against the real
 *     destination post type and rewrites the payload markers to nulls/empties.
 *     No side effects happen here.
 *  2. `rest_dispatch_request` runs only after the native endpoint permission
 *     callback passed. It atomically claims the idempotency key (or replays a
 *     completed claim, or answers 425 while another request holds the key),
 *     finishes an expired create claim only with explicit completion proof,
 *     replays a create whose durable identity proves completion after its
 *     claim row was pruned as history, snapshots current ACF values for
 *     updates, sideloads the binaries and re-runs the native validators
 *     against the created attachment ids.
 *  3. A `rest_insert_{$postType}` listener attempts to store an unresolved
 *     create identity, then records the saved post id in the claim. Neither
 *     write proves native/ACF completion. Active claims survive history GC
 *     even if both writes fail.
 *     A matching `rest_after_insert_{$postType}` records native saving, not
 *     attachment finalization or operation completion.
 *  4. `rest_request_after_callbacks` finalizes: on success the durable
 *     identity is completed first and only then the claim is completed under
 *     the owner token, so claim completion can never outlive the identity;
 *     only the claim transition winner attempts the completion action. A create
 *     whose identity cannot be completed is answered with the controlled
 *     recovery-pending response instead of a false success, keeping the
 *     active claim so a retry cannot create a duplicate. On failure the
 *     request-owned changes are recovered (created post deleted, update
 *     snapshots restored, created attachments deleted) and the claim is only
 *     released when the recovery fully succeeded. Running finalization in
 *     this filter also makes internal `rest_do_request()` dispatches
 *     finalize correctly, since `rest_post_dispatch` only runs on the outer
 *     HTTP serving path.
 *  5. `rest_post_dispatch` is kept as a safety net for responses that bypass
 *     the callback filters.
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
     * Seconds an active idempotency claim stays locked before it may be
     * reclaimed or adopted.
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
     * Reserved multipart part that lists explicit empty-list paths.
     */
    private const EMPTY_FIELD = '_acf_rest_empty';

    /**
     * Request parameter that carries the ACF payload.
     */
    private const ACF_PARAM = 'acf';

    /**
     * Routes that accept multipart uploads, mapped to their post type.
     *
     * The values must be the registered post type names: WordPress fires
     * `rest_insert_{$postType}` with the registered name and ACF locates
     * field groups on it.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        '/wp/v2/sponsor-assignments' => 'assignment',
        '/wp/v2/sponsor-offerings' => 'offering',
    ];

    /**
     * Methods that accept multipart uploads.
     *
     * Only POST is claimed: native PHP populates the multipart file arrays
     * for POST requests only, so PUT/PATCH multipart bodies cannot be parsed
     * by the receiver. Requests without the version header (JSON payloads)
     * are never affected by this class.
     *
     * @var list<string>
     */
    private const METHODS = ['POST'];

    /**
     * ACF field types that may carry a multipart file reference.
     *
     * @var list<string>
     */
    private const UPLOAD_FIELD_TYPES = ['image', 'file', 'gallery'];

    /**
     * Action attempted after a protocol request fully succeeded, at most
     * once per idempotency key. Sponsor specific consumers hook this action;
     * the receiver itself owns no sponsor policy.
     */
    public const ACTION_AFTER_INSERT = 'AcfRestUpload/afterInsertPost';
    public const ACTION_BEGIN_OPERATION = 'AcfRestUpload/beginOperation';
    public const ACTION_END_OPERATION = 'AcfRestUpload/endOperation';

    /**
     * Post meta that stores the bounded recent list of idempotency keys a
     * post was created/updated with.
     *
     * This list is update history only and carries no completion proof. The
     * permanent create identity lives in META_CREATE_IDENTITY.
     */
    public const META_KEYS = '_acf_rest_upload_keys';

    /**
     * Maximum number of idempotency keys kept per post.
     */
    private const POST_KEY_LIMIT = 10;

    /**
     * Post meta that stores the permanent create identity of a post.
     *
     * The value records the normalized claim scope (route, method, user and
     * UUID) as its claim option name, plus the operation status. It is
     * first attempted as unresolved when WordPress creates the post. It is only
     * marked complete, as a checked write, before the claim completes, so
     * claim completion can never persist without a complete identity.
     * It exists for the post lifetime: a replay after the claim history
     * pruned the option row is served only from a complete identity, and
     * the meta is removed with its post.
     */
    public const META_CREATE_IDENTITY = '_acf_rest_upload_create_identity';

    /**
     * Request scoped state keyed by `spl_object_id()`.
     *
     * @var array<int, array{
     *     references: list<FileReference>,
     *     files: array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}>,
     *     attachments: list<int>,
     *     needsSanitization: bool,
     *     finalized: bool,
     *     key: string,
     *     idempotencyOption: string,
     *     owner: string|null,
     *     request: WP_REST_Request,
     *     postType: string,
     *     isCreate: bool,
     *     targetPostId: int|null,
     *     savedPostId: int|null,
     *     nativeSaved: bool,
     *     progressFailed: bool,
     *     touchedFields: list<string>,
     *     galleryFields: list<string>,
     *     updateSnapshot: UpdateSnapshot|null,
     *     insertHook: array{name: string, closure: callable, afterName: string, afterClosure: callable}|null
     * }>
     */
    private array $contexts = [];

    private IdempotencyStore|null $idempotencyStore = null;

    /**
     * Register the REST lifecycle hooks.
     */
    public function addHooks(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'preDispatch'], 1, 3);
        add_filter('rest_request_before_callbacks', [$this, 'beforeCallbacks'], 10, 3);
        add_filter('rest_dispatch_request', [$this, 'dispatchRequest'], 10, 4);
        add_filter('rest_request_after_callbacks', [$this, 'afterCallbacks'], PHP_INT_MAX, 3);
        add_filter('rest_post_dispatch', [$this, 'postDispatch'], PHP_INT_MAX, 3);
    }

    /**
     * Validate and decode the multipart upload before the route is dispatched.
     *
     * This stage is strictly read/rewrite only: no attachments are created,
     * nothing is claimed and no error leaks side effects.
     *
     * @param mixed $response
     * @param mixed $server
     */
    public function preDispatch($response, $server, WP_REST_Request $request): mixed
    {
        $version = $request->get_header(self::VERSION_HEADER);

        if ($version === null || $version === '') {
            // Not a multipart upload request; JSON payloads pass through.
            return $response;
        }

        if ((string) $version !== self::PROTOCOL_VERSION) {
            return $this->error('acf_rest_upload_unsupported_version', 400, 'Unsupported ACF REST upload protocol version.');
        }

        $target = $this->resolveRouteTarget($request);

        if ($target === null) {
            return $this->error('acf_rest_upload_unsupported_request', 400, 'ACF REST uploads are not supported for this route or method.');
        }

        if (!$target['isCreate']) {
            $allowed = ['title', 'status', self::ACF_PARAM, self::NULLS_FIELD, self::EMPTY_FIELD, 'context', '_fields', '_embed', '_wpnonce'];
            foreach ($request->get_params() as $name => $value) {
                if ($name === 'id' && is_numeric($value) && (string) $value === (string) $target['targetPostId']) {
                    continue;
                }
                if (!in_array($name, $allowed, true)) {
                    return $this->error('acf_rest_upload_unsupported_update', 400, 'Multipart updates support only title, status, and acf.');
                }
            }
        }

        if (!current_user_can('upload_files')) {
            return $this->error('acf_rest_upload_forbidden', 403, 'You are not allowed to upload files.');
        }

        $idempotencyError = $this->validateIdempotencyKey($request);

        if ($idempotencyError !== null) {
            return $idempotencyError;
        }

        $key = (string) $this->resolveIdempotencyKey($request);

        try {
            $this->validateReservedParts($request);
        } catch (InvalidRequestException $exception) {
            return $this->error('acf_rest_upload_conflicting_parts', 400, $exception->getMessage());
        }

        $acf = $request->get_param(self::ACF_PARAM);

        if ($acf === null) {
            $acf = [];
        }

        if (!is_array($acf)) {
            return $this->error('acf_rest_upload_invalid_request', 400, 'The acf parameter must be an object.');
        }

        foreach ([self::FILES_FIELD, self::NULLS_FIELD, self::EMPTY_FIELD] as $reserved) {
            if (array_key_exists($reserved, $acf)) {
                return $this->error(
                    'acf_rest_upload_conflicting_parts',
                    400,
                    sprintf('The acf payload must not contain the reserved protocol key "%s".', $reserved)
                );
            }
        }

        $references = (new FileReferenceCollector())->collect($acf);

        try {
            $files = $this->flattenUploadedFiles($request->get_file_params());
        } catch (InvalidRequestException $exception) {
            return $this->error('acf_rest_upload_conflicting_parts', 400, $exception->getMessage());
        }

        $referenceError = $this->validateReferences(
            $references,
            $target['postType'],
            $target['targetPostId'],
            strtoupper((string) $request->get_method())
        );

        if ($referenceError !== null) {
            return $referenceError;
        }

        try {
            (new PartKeyValidator())->validate($references, array_keys($files));
        } catch (InvalidRequestException $exception) {
            return $this->error(InvalidRequestException::CODE, 400, $exception->getMessage());
        }

        try {
            $paths = $this->resolveReservedPaths($request, $references, $target['postType'], $target['targetPostId']);
        } catch (InvalidRequestException $exception) {
            return $this->error(InvalidRequestException::CODE, 400, $exception->getMessage());
        }

        try {
            // Clear the markers, restore explicit nulls and empty lists before
            // ACF validates the payload.
            $acf = (new NullInjector())->inject($acf, [...$paths['references'], ...$paths['nulls']]);
            $acf = (new NullInjector())->injectEmptyArrays($acf, $paths['empties']);
        } catch (InvalidArgumentException $exception) {
            return $this->error(InvalidRequestException::CODE, 400, $exception->getMessage());
        }

        $request->set_param(self::ACF_PARAM, $acf);

        $this->contexts[spl_object_id($request)] = [
            'request' => $request,
            'references' => $references,
            'files' => $files,
            'attachments' => [],
            'needsSanitization' => false,
            'finalized' => false,
            'key' => $key,
            'idempotencyOption' => (string) $this->idempotencyOptionName($request),
            'owner' => null,
            'postType' => $target['postType'],
            'isCreate' => $target['isCreate'],
            'targetPostId' => $target['targetPostId'],
            'savedPostId' => null,
            'nativeSaved' => false,
            'progressFailed' => false,
            'touchedFields' => $paths['touchedFields'],
            'galleryFields' => $paths['galleryFields'],
            'updateSnapshot' => null,
            'insertHook' => null,
        ];

        return $response;
    }

    /**
     * Defer ACF validation until uploaded references have attachment IDs.
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

        if ($this->contexts[$contextId]['references'] === [] || !is_wp_error($response)) {
            return $response;
        }

        $data = $response->get_error_data('rest_invalid_param');
        if ($response->get_error_codes() !== ['rest_invalid_param']
            || !is_array($data)
            || !is_array($data['params'] ?? null)
            || array_keys($data['params'] ?? []) !== [self::ACF_PARAM]) {
            return $response;
        }

        // Core skips sanitization when validation fails. Repeat it only then.
        $this->contexts[$contextId]['needsSanitization'] = true;

        return null;
    }

    /**
     * Claim the idempotency key and resolve the uploads.
     *
     * Runs after the native endpoint permission callback passed and before
     * the native callback, so replays and conflicts are only served to
     * callers the endpoint itself already authorized.
     *
     * @param mixed $response
     * @param mixed $route
     * @param mixed $handler
     */
    public function dispatchRequest($response, WP_REST_Request $request, $route, $handler): mixed
    {
        $contextId = spl_object_id($request);

        if ($response !== null || !isset($this->contexts[$contextId])) {
            return $response;
        }

        $claim = $this->acquireIdempotency($contextId);

        if ($claim !== null) {
            return $claim;
        }

        $snapshotError = $this->snapshotUpdate($contextId, $request);
        if ($snapshotError !== null) {
            return $snapshotError;
        }

        if ($this->contexts[$contextId]['references'] !== []) {
            $resolution = $this->resolveUploads($contextId, $request);

            if ($resolution !== null) {
                return $resolution;
            }
        }

        return $response;
    }

    /**
     * Finalize the request right after the native callbacks, for both HTTP
     * and internal `rest_do_request()` dispatches.
     *
     * @param mixed $response
     * @param mixed $handler
     */
    public function afterCallbacks($response, $handler, WP_REST_Request $request): mixed
    {
        $contextId = spl_object_id($request);

        if (isset($this->contexts[$contextId])) {
            return $this->postDispatch($response, null, $request);
        }

        return $response;
    }

    /**
     * Safety net finalization for responses that bypass the callback filters.
     *
     * @param mixed $response
     * @param mixed $server
     */
    public function postDispatch($response, $server, WP_REST_Request $request): mixed
    {
        $contextId = spl_object_id($request);

        if (isset($this->contexts[$contextId])) {
            $context = $this->contexts[$contextId];
            try {
                $response = $this->finalize($contextId, $response) ?? $response;
            } finally {
                unset($this->contexts[$contextId]);
                if ($context['owner'] !== null) {
                    do_action(self::ACTION_END_OPERATION, $context['idempotencyOption'], $request);
                }
            }
        }

        return $response;
    }

    /**
     * Resolve the uploads after core grants endpoint permission, before native
     * saving.
     *
     * Returns an error response after recovering the request, or null to
     * continue into the native callback.
     */
    private function resolveUploads(int $contextId, WP_REST_Request $request): ?WP_Error
    {
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
                return $this->error('acf_rest_upload_invalid_file', 400, 'An uploaded file part is invalid.');
            }

            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);

            if ($error !== UPLOAD_ERR_OK || $tmpName === '' || $size <= 0) {
                return $this->error('acf_rest_upload_invalid_file', 400, 'An uploaded file part is invalid.');
            }

            $attachmentId = media_handle_sideload([
                'name' => (string) ($file['name'] ?? ''),
                'type' => (string) ($file['type'] ?? ''),
                'tmp_name' => $tmpName,
                'error' => $error,
                'size' => $size,
            ], 0);

            if (is_wp_error($attachmentId)) {
                return $this->classifyUploadError($attachmentId);
            }

            $this->contexts[$contextId]['attachments'][] = (int) $attachmentId;
            if (!$this->recordProgress($contextId, 'active')) {
                return $this->error('acf_rest_upload_recovery_pending', 500, 'Could not persist upload recovery information.');
            }

            foreach ($paths as $path) {
                $acf = $this->setValueAtPath($acf, BracketPath::parse($path), (int) $attachmentId);
            }
        }

        // Existing attachment ids arrive as multipart strings; canonicalize
        // them back to integers while preserving order and keys so mixed
        // galleries keep their exact composition.
        foreach ($this->contexts[$contextId]['galleryFields'] as $fieldName) {
            if (array_key_exists($fieldName, $acf)) {
                $acf[$fieldName] = $this->coerceGalleryIdList($acf[$fieldName]);
            }
        }

        $request->set_param(self::ACF_PARAM, $acf);

        // Run the original route validators, including ACF attachment constraints.
        $validation = $request->has_valid_params();
        if (is_wp_error($validation)) {
            return $validation;
        }

        if ($this->contexts[$contextId]['needsSanitization']) {
            $sanitization = $request->sanitize_params();
            if (is_wp_error($sanitization)) {
                return $sanitization;
            }
        }

        return null;
    }

    /**
     * Atomically claim the idempotency key, or replay/answer a conflict.
     *
     * Returns a response to short-circuit the native callback (replay,
     * retryable in-progress, or the controlled recovery-pending response for
     * a recorded resource whose durable identity could not be completed), or
     * null when this request now owns a fresh claim.
     */
    private function acquireIdempotency(int $contextId): ?WP_REST_Response
    {
        $context = $this->contexts[$contextId];
        $option = $context['idempotencyOption'];
        $store = $this->idempotencyStore();

        $state = $store->read($option);

        if ($store->isComplete($state)) {
            return $this->replayResponse($store->completedPostId($state), $context['isCreate'], $contextId);
        }

        if (is_array($state) && ($state['phase'] ?? null) === 'recovery_failed') {
            return $this->recoveryPendingResponse();
        }

        if ($store->isExpiredActive($state)) {
            return $this->tryAdoptSavedResource($contextId, $store);
        }

        if ($store->isActive($state)) {
            return $this->inProgressResponse($store->activeExpiresAt($state));
        }

        // No usable claim row is left. A create consults the durable
        // identity before a fresh claim: it replays only when the identity
        // proves the original operation completed, so a completed create
        // still replays after its claim row was pruned as history.
        if ($context['isCreate']) {
            $completedPostId = $this->findCreatePost($option);

            if ($completedPostId !== null) {
                $identity = get_post_meta($completedPostId, self::META_CREATE_IDENTITY, true);
                if (($identity['status'] ?? null) !== 'complete') {
                    return $this->recoveryPendingResponse();
                }
                return $this->replayResponse($completedPostId, true, $contextId);
            }
        }

        $owner = $store->newOwner();

        $request = $context['request'];
        $scope = ['route' => $request->get_route(), 'method' => $request->get_method(), 'user_id' => get_current_user_id(), 'key' => $context['key']];
        if (!$store->tryClaim($option, $store->activeState($owner, self::ACTIVE_LOCK_TTL, $scope))) {
            // A concurrent caller won the insert; re-read and answer.
            $state = $store->read($option);

            if ($store->isComplete($state)) {
                return $this->replayResponse($store->completedPostId($state), $context['isCreate'], $contextId);
            }

            return $this->inProgressResponse($store->activeExpiresAt($state));
        }

        $store->trackClaim($option);
        $this->bindClaim($contextId, $owner);

        return null;
    }

    /**
     * Bind a successful claim and its owner token to the current request.
     */
    private function bindClaim(int $contextId, string $owner): void
    {
        $this->contexts[$contextId]['owner'] = $owner;
        $this->registerInsertHook($contextId);
        do_action(self::ACTION_BEGIN_OPERATION, $this->contexts[$contextId]['idempotencyOption'], $owner, $this->contexts[$contextId]['request']);
    }

    /**
     * Finish a claim only if the create identity already proves completion.
     * An expired lock or a saved post ID alone never proves native success.
     * Unresolved creates and updates require recovery, not automatic saving.
     */
    private function tryAdoptSavedResource(int $contextId, IdempotencyStore $store): WP_REST_Response
    {
        $context = $this->contexts[$contextId];
        $option = $context['idempotencyOption'];
        $state = $store->read($option);

        if ($store->isComplete($state)) {
            return $this->replayResponse($store->completedPostId($state), $context['isCreate'], $contextId);
        }
        if (!$context['isCreate']) {
            return $this->recoveryPendingResponse();
        }

        $savedPostId = $this->findCreatePost($option);
        $identity = $savedPostId === null ? null : get_post_meta($savedPostId, self::META_CREATE_IDENTITY, true);
        if (!is_array($identity) || ($identity['status'] ?? null) !== 'complete') {
            return $this->recoveryPendingResponse();
        }

        // Only the CAS winner may attempt the completion notification.
        $owner = $store->tryAdopt($option, $savedPostId, $state);

        if ($owner === false) {
            $state = $store->read($option);
            return $store->isComplete($state)
                ? $this->replayResponse($store->completedPostId($state), true, $contextId)
                : $this->recoveryPendingResponse();
        }

        $this->fireCompletedNotification($savedPostId, $option);

        return $this->replayResponse($savedPostId, $context['isCreate'], $contextId);
    }

    /**
     * Register the native insert listener that durably records the resource.
     */
    private function registerInsertHook(int $contextId): void
    {
        $context = $this->contexts[$contextId];
        $hook = 'rest_insert_' . $context['postType'];

        $listener = function ($post, $restRequest, $creating, bool $saved = false) use ($contextId, $context): void {
            if (($this->contexts[$contextId]['finalized'] ?? true)
                || $restRequest !== $context['request']
                || !$post instanceof WP_Post
                || $post->post_type !== $context['postType']
                || $creating !== $context['isCreate']
                || (!$context['isCreate'] && (int) $post->ID !== $context['targetPostId'])) {
                return;
            }

            if ($saved) {
                if (($this->contexts[$contextId]['savedPostId'] ?? null) === (int) $post->ID) {
                    $this->contexts[$contextId]['nativeSaved'] = $this->recordProgress($contextId, 'saved');
                }
                return;
            }
            $this->onRestInsert($contextId, $post);
        };

        $afterHook = 'rest_after_insert_' . $context['postType'];
        $afterListener = static function ($post, $restRequest, $creating) use ($listener): void {
            $listener($post, $restRequest, $creating, true);
        };
        add_action($hook, $listener, 10, 3);
        add_action($afterHook, $afterListener, 10, 3);

        $this->contexts[$contextId]['insertHook'] = ['name' => $hook, 'closure' => $listener, 'afterName' => $afterHook, 'afterClosure' => $afterListener];
    }

    private function recordProgress(int $contextId, string $phase): bool
    {
        $context = $this->contexts[$contextId];
        $recorded = $this->idempotencyStore()->tryRecordProgress(
            $context['idempotencyOption'], (string) $context['owner'], $phase, $context['attachments']
        );
        if (!$recorded) {
            $this->contexts[$contextId]['progressFailed'] = true;
        }
        return $recorded;
    }

    /**
     * Durably record the saved resource identity as soon as WordPress created
     * or loaded the target post, before ACF saves the fields.
     *
     * Creates attempt an unresolved identity first. Record the resource even
     * if that write fails. The active claim blocks GC and fresh creation,
     * including when neither resource write succeeds.
     */
    private function onRestInsert(int $contextId, mixed $post): void
    {
        $context = $this->contexts[$contextId] ?? null;

        if ($context === null || $context['finalized'] || !$post instanceof WP_Post) {
            return;
        }

        $postId = (int) $post->ID;

        $this->contexts[$contextId]['savedPostId'] = $postId;

        if (!$context['isCreate']) {
            $this->idempotencyStore()->tryRecordSavedPost(
                $context['idempotencyOption'],
                (string) $context['owner'],
                $postId
            );
            $this->recordPostKey($postId, $context['key']);

            return;
        }

        $identityPersisted = $this->recordCreateIdentity($postId, $context['idempotencyOption']);

        $this->idempotencyStore()->tryRecordSavedPost(
            $context['idempotencyOption'],
            (string) $context['owner'],
            $postId
        );

        if ($identityPersisted) {
            $this->recordPostKey($postId, $context['key']);
        }
    }

    /**
     * Add the idempotency key to the bounded recent key list of the post.
     *
     * The list is update history of fixed size. It is never a completion
     * proof: the permanent create identity is recorded separately (see
     * recordCreateIdentity).
     */
    private function recordPostKey(int $postId, string $key): void
    {
        $existing = get_post_meta($postId, self::META_KEYS, true);
        $keys = is_array($existing) ? array_values(array_filter($existing, 'is_string')) : [];

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }

        if (count($keys) > self::POST_KEY_LIMIT) {
            $keys = array_slice($keys, -self::POST_KEY_LIMIT);
        }

        update_post_meta($postId, self::META_KEYS, $keys);
    }

    /**
     * Record the pending create identity of a new post.
     *
     * The identity starts unresolved: a recorded post alone never proves
     * that the original operation completed. The write result is checked.
     * An identity that is already stored for this claim scope counts as
     * persisted (a re-fired insert hook never downgrades or rewrites it);
     * a foreign identity fails closed.
     */
    private function recordCreateIdentity(int $postId, string $optionName): bool
    {
        $identity = get_post_meta($postId, self::META_CREATE_IDENTITY, true);

        if (is_array($identity)) {
            if (($identity['option'] ?? null) !== $optionName) {
                return false;
            }

            return true;
        }

        return false !== update_post_meta($postId, self::META_CREATE_IDENTITY, [
            'option' => $optionName,
            'status' => 'unresolved',
        ]);
    }

    /**
     * Complete the create identity of a post, before the claim completes.
     *
     * The write result is checked. An identity of the same scope is upgraded
     * to complete, an already complete identity stays untouched (the
     * existing value counts as persisted), a missing identity is written
     * complete directly, and a foreign identity fails closed. Only successful
     * native finalization calls this method, never interrupted-claim recovery.
     */
    private function completeCreateIdentity(int $postId, string $optionName): bool
    {
        $identity = get_post_meta($postId, self::META_CREATE_IDENTITY, true);

        if (is_array($identity)) {
            if (($identity['option'] ?? null) !== $optionName) {
                return false;
            }

            if (($identity['status'] ?? null) === 'complete') {
                return true;
            }
        }

        return false !== update_post_meta($postId, self::META_CREATE_IDENTITY, [
            'option' => $optionName,
            'status' => 'complete',
        ]);
    }

    /**
     * Find a post by the full create scope, including unresolved identities.
     * The caller must check completion before replaying the operation.
     */
    private function findCreatePost(string $optionName): ?int
    {
        if (!function_exists('get_posts')) {
            return null;
        }

        $posts = get_posts([
            'post_type' => array_values(self::ROUTES),
            // "any" excludes trash and some internal statuses. Identity
            // lasts until deletion, not merely until the post is trashed.
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => self::META_CREATE_IDENTITY,
                'value' => '"' . $optionName . '"',
                'compare' => 'LIKE',
            ]],
        ]);

        $postId = is_array($posts) ? reset($posts) : false;

        if (!is_numeric($postId) || (int) $postId <= 0) {
            return null;
        }

        $postId = (int) $postId;

        if (get_post($postId) === null) {
            return null;
        }

        $identity = get_post_meta($postId, self::META_CREATE_IDENTITY, true);

        if (!is_array($identity)
            || ($identity['option'] ?? null) !== $optionName) {
            return null;
        }

        return $postId;
    }

    /**
     * Find a post that carries the idempotency key in its durable meta.
     */
    private function findPostByIdempotencyKey(string $key): ?int
    {
        if (!function_exists('get_posts')) {
            return null;
        }

        $posts = get_posts([
            'post_type' => array_values(self::ROUTES),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => self::META_KEYS,
                'value' => '"' . $key . '"',
                'compare' => 'LIKE',
            ]],
        ]);

        $postId = is_array($posts) ? reset($posts) : false;

        return is_numeric($postId) && (int) $postId > 0 ? (int) $postId : null;
    }

    /**
     * Finalize the request exactly once.
     *
     * Success: parent the created attachments, complete the durable identity
     * and then the claim under the owner token, and fire the neutral
     * completion action once. Failure: recover request-owned changes and
     * release the claim only when the recovery fully succeeded.
     *
     * Returns a replacement response when the request must not keep its
     * original response, or null to keep it. A create whose durable identity
     * could not be completed keeps its active claim and is answered with the
     * recovery-pending response instead of a false success.
     */
    private function finalize(int $contextId, mixed $response): ?WP_REST_Response
    {
        $context = $this->contexts[$contextId];

        if ($context['finalized']) {
            return null;
        }

        $this->contexts[$contextId]['finalized'] = true;
        $this->unregisterInsertHook($contextId);

        if ($this->isErrorResponse($response)) {
            $this->recoverFailure($contextId);

            return null;
        }

        if ($context['owner'] !== null && (!$context['nativeSaved'] || $context['progressFailed'])) {
            return $this->recoveryPendingResponse();
        }

        $postId = $context['savedPostId'] ?? $this->resolveResponsePostId($response);

        if ($postId !== null && $postId > 0) {
            foreach ($context['attachments'] as $attachmentId) {
                // Parenting is part of completion, not optional cleanup.
                $parented = wp_update_post([
                    'ID' => $attachmentId,
                    'post_parent' => $postId,
                ], true);
                if (is_wp_error($parented) || !$parented || (int) (get_post($attachmentId)->post_parent ?? 0) !== $postId) {
                    $this->recoverFailure($contextId);
                    return $this->recoveryPendingResponse();
                }
            }
        }

        $this->deleteTempFiles($contextId);

        if ($context['owner'] !== null) {
            // Creates complete the durable identity BEFORE the claim: claim
            // completion must never persist while the permanent identity is
            // absent or unresolved, because a completed claim without a
            // complete identity permits a duplicate create once the history
            // GC pruned the claim row.
            $identityComplete = !$context['isCreate']
                || ($postId !== null && $postId > 0
                    && $this->completeCreateIdentity($postId, $context['idempotencyOption']));

            if (!$identityComplete) {
                // Controlled recovery failure: the create finished natively,
                // but its permanent identity is not persisted. No completion,
                // no notification, and no false success: the original
                // response is replaced and the active claim is kept. Retries
                // stay unresolved; restoring persistence is not completion proof.
                return $this->recoveryPendingResponse();
            }

            $completed = $this->idempotencyStore()->tryComplete(
                $context['idempotencyOption'],
                (string) $context['owner'],
                $postId
            );

            if ($completed) {
                $this->fireCompletedNotification($postId, $context['idempotencyOption']);
            }
            // A failed CAS can mean a database failure or a concurrent winner.
            // A retry may finish an expired create claim only if the complete
            // identity persisted. It cannot infer completion for an update.
        }

        return null;
    }

    /**
     * Recover request-owned changes after a failed request.
     *
     * Created drafts are deleted, updated ACF values are restored from the
     * snapshot, and attachments created by this request are deleted. Old and
     * shared attachments are never touched. The claim is released only when
     * every recovery step succeeded; otherwise the active claim is retained
     * and retries require explicit recovery.
     */
    private function recoverFailure(int $contextId): void
    {
        $context = $this->contexts[$contextId];
        $recoveryFailed = false;

        if ($context['isCreate'] && $context['savedPostId'] !== null) {
            $deleted = wp_delete_post($context['savedPostId'], true);

            if ($deleted === false || $deleted === null) {
                $recoveryFailed = true;
            }
        }

        if ($context['updateSnapshot'] !== null && !$context['updateSnapshot']->restore()) {
            $recoveryFailed = true;
        }

        foreach ($context['attachments'] as $attachmentId) {
            if (wp_delete_attachment($attachmentId, true) === false) {
                $recoveryFailed = true;
            }
        }

        $this->deleteTempFiles($contextId);

        if ($recoveryFailed && $context['owner'] !== null) {
            $this->recordProgress($contextId, 'recovery_failed');
        }
        $this->contexts[$contextId]['attachments'] = [];
        if (!$recoveryFailed && $context['owner'] !== null) {
            $this->idempotencyStore()->tryRelease(
                $context['idempotencyOption'],
                (string) $context['owner']
            );
        }
    }

    /**
     * Capture all supported submitted values before native saving or uploads.
     */
    private function snapshotUpdate(int $contextId, WP_REST_Request $request): ?WP_Error
    {
        $context = $this->contexts[$contextId];

        if ($context['isCreate'] || $context['targetPostId'] === null) {
            return null;
        }
        $post = get_post($context['targetPostId']);
        if (!$post instanceof WP_Post || $post->post_type !== $context['postType']) {
            return $this->error('acf_rest_upload_snapshot_failed', 400, 'The update target is unavailable.');
        }
        $snapshot = UpdateSnapshot::capture($post, $request->get_params(),
            fn (string $name): ?array => $this->resolveDestinationField($context['postType'], $context['targetPostId'], $name, $request->get_method())
        );
        if ($snapshot instanceof WP_Error) {
            return $snapshot;
        }
        $this->contexts[$contextId]['updateSnapshot'] = $snapshot;
        return null;
    }

    /**
     * Remove the per-request native insert listener.
     */
    private function unregisterInsertHook(int $contextId): void
    {
        $hook = $this->contexts[$contextId]['insertHook'];

        if ($hook !== null) {
            remove_action($hook['name'], $hook['closure']);
            remove_action($hook['afterName'], $hook['afterClosure']);
        }

        $this->contexts[$contextId]['insertHook'] = null;
    }

    /**
     * Mark a context as finalized without further recovery.
     */
    private function markFinalized(int $contextId): void
    {
        $this->contexts[$contextId]['finalized'] = true;
        $this->unregisterInsertHook($contextId);
    }

    /**
     * Fire the neutral completion action once per completed claim.
     */
    private function fireCompletedNotification(?int $postId, string $option): void
    {
        do_action(self::ACTION_AFTER_INSERT, $postId, $option);
    }

    /**
     * Remove leftover temporary files of parts that were never moved.
     */
    private function deleteTempFiles(int $contextId): void
    {
        foreach ($this->contexts[$contextId]['files'] as $file) {
            $tmpName = $file['tmp_name'] ?? null;

            if (is_string($tmpName) && $tmpName !== '' && file_exists($tmpName)) {
                @unlink($tmpName);
            }
        }
    }

    /**
     * Validate that the reserved protocol parts are structurally sound.
     *
     * The binaries must only travel as `_acf_rest_files[<key>]` file parts; a
     * body field with that name is a conflicting duplicate. File params must
     * only contain the reserved files namespace.
     *
     * @throws InvalidRequestException When parts conflict.
     */
    private function validateReservedParts(WP_REST_Request $request): void
    {
        $bodyParams = $request->get_body_params();

        if (array_key_exists(self::FILES_FIELD, $bodyParams)) {
            throw InvalidRequestException::invalidRequest(
                sprintf('The reserved part "%s" must only be sent as a file part, not as a body field.', self::FILES_FIELD)
            );
        }

        foreach (array_keys($request->get_file_params()) as $name) {
            if ($name !== self::FILES_FIELD) {
                throw InvalidRequestException::invalidRequest(
                    sprintf('Unexpected file part "%s". Only "%s[<key>]" parts are accepted.', (string) $name, self::FILES_FIELD)
                );
            }
        }
    }

    /**
     * Resolve the explicit null and empty-list paths from their reserved
     * parts, enforcing the rooted path contract.
     *
     * Both lists use bracket paths rooted at `acf`, relative to the request
     * root (`acf[optional_image]`, `acf[gallery][1]`). A null or empty path
     * that collides with a file reference path is rejected as ambiguous.
     *
     * @param list<FileReference> $references
     * @param int|null            $postId Target post id for updates, null for creates.
     *
     * @return array{references: list<string>, nulls: list<string>, empties: list<string>, touchedFields: list<string>, galleryFields: list<string>}
     *
     * @throws InvalidRequestException When a path violates the contract.
     */
    private function resolveReservedPaths(WP_REST_Request $request, array $references, string $postType, ?int $postId): array
    {
        $referencePaths = [];
        $referenceSegments = [];
        $touchedFields = [];

        foreach ($references as $reference) {
            $referencePaths[] = $reference->path;
            $segments = BracketPath::parse($reference->path);
            $referenceSegments[] = $segments;

            $root = $segments[0] ?? null;

            if (is_string($root) && $root !== '' && !in_array($root, $touchedFields, true)) {
                $touchedFields[] = $root;
            }
        }

        $nullSegments = $this->collectReservedListSegments($request, self::NULLS_FIELD);
        $emptySegments = $this->collectReservedListSegments($request, self::EMPTY_FIELD);

        foreach ([...$nullSegments, ...$emptySegments] as $segments) {
            foreach ($referenceSegments as $candidate) {
                if ($this->pathsConflict($segments, $candidate)) {
                    throw InvalidRequestException::invalidRequest(
                        sprintf(
                            'The path "%s" collides with the file reference "%s".',
                            BracketPath::format($segments),
                            BracketPath::format($candidate)
                        )
                    );
                }
            }

            // Nulls and empties also replace field values, so updates must
            // snapshot those fields for recovery as well.
            $root = $segments[0] ?? null;

            if (is_string($root) && $root !== '' && !in_array($root, $touchedFields, true)) {
                $touchedFields[] = $root;
            }
        }

        foreach ($nullSegments as $segments) {
            foreach ($emptySegments as $candidate) {
                if ($this->pathsConflict($segments, $candidate)) {
                    throw InvalidRequestException::invalidRequest(
                        sprintf(
                            'The path "%s" is listed as both null and empty.',
                            BracketPath::format($segments)
                        )
                    );
                }
            }
        }

        $galleryFields = [];

        foreach ($touchedFields as $fieldName) {
            $field = $this->resolveDestinationField(
                $postType,
                $postId,
                $fieldName,
                strtoupper((string) $request->get_method())
            );

            if (is_array($field) && ($field['type'] ?? null) === 'gallery') {
                $galleryFields[] = $fieldName;
            }
        }

        return [
            'references' => $referencePaths,
            'nulls' => array_map([BracketPath::class, 'format'], $nullSegments),
            'empties' => array_map([BracketPath::class, 'format'], $emptySegments),
            'touchedFields' => $touchedFields,
            'galleryFields' => $galleryFields,
        ];
    }

    /**
     * Read one reserved path list and parse it into rooted segments.
     *
     * @return list<list<int|string|null>>
     *
     * @throws InvalidRequestException When the list or a path is invalid.
     */
    private function collectReservedListSegments(WP_REST_Request $request, string $field): array
    {
        $list = $request->get_param($field);

        if ($list === null) {
            return [];
        }

        if (!is_array($list)) {
            throw InvalidRequestException::invalidRequest(
                sprintf('The reserved part "%s" must be a list of bracket paths.', $field)
            );
        }

        $segments = [];

        foreach ($list as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw InvalidRequestException::invalidRequest(
                    sprintf('The reserved part "%s" must only contain non-empty bracket path strings.', $field)
                );
            }

            $parsed = $this->rootedAcfSegments(trim($path));

            if ($parsed === null) {
                throw InvalidRequestException::invalidRequest(
                    sprintf('The path "%s" must be a bracket path rooted at "acf[...]".', trim($path))
                );
            }

            $segments[] = $parsed;
        }

        return $segments;
    }

    /**
     * Parse a request-rooted bracket path that must start with "acf".
     *
     * @return list<int|string|null>|null Null when the path violates the contract.
     */
    private function rootedAcfSegments(string $path): ?array
    {
        try {
            $segments = BracketPath::parse($path, true);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (($segments[0] ?? null) !== self::ACF_PARAM) {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return null;
        }

        return $segments;
    }

    /**
     * Whether two parsed paths address overlapping positions.
     *
     * An append segment ("[]") conservatively conflicts with any explicit
     * segment at the same position, and a path that is a prefix of another
     * always conflicts (the shorter would replace the container of the
     * longer).
     *
     * @param list<int|string|null> $a
     * @param list<int|string|null> $b
     */
    private function pathsConflict(array $a, array $b): bool
    {
        $length = min(count($a), count($b));

        for ($index = 0; $index < $length; $index++) {
            $left = $a[$index];
            $right = $b[$index];

            if ($left === $right) {
                continue;
            }

            if ($left === null || $right === null) {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * Validate that every file reference targets an enabled top-level ACF
     * field of the real destination resource.
     *
     * A reference is only accepted when its path is exactly a top-level field
     * name, or, for gallery fields, the field name followed by a single
     * numeric index. The field must be an image/file/gallery field with the
     * `allow_multipart_rest_upload` setting enabled, and it must resolve to
     * exactly one REST exposed field group located on the destination
     * resource. Ambiguous field names are rejected.
     *
     * @param list<FileReference> $references
     * @param int|null            $postId     Target post id for updates, null for creates.
     * @param string              $httpMethod The HTTP method of the request, passed to `acf/rest/get_fields`.
     */
    private function validateReferences(array $references, string $postType, ?int $postId, string $httpMethod): ?WP_Error
    {
        if ($references === []) {
            return null;
        }

        if (!function_exists('acf_get_field_group')
            || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')
            || !function_exists('acf_get_field_type')) {
            return $this->invalidReferenceError();
        }

        foreach ($references as $reference) {
            if ($reference->path === '') {
                return $this->invalidReferenceError();
            }

            try {
                $segments = BracketPath::parse($reference->path);
            } catch (InvalidArgumentException) {
                return $this->invalidReferenceError();
            }

            // The marker must always start at a top-level field name.
            $fieldName = $segments[0] ?? null;

            if (!is_string($fieldName) || $fieldName === '') {
                return $this->invalidReferenceError();
            }

            $field = $this->resolveDestinationField($postType, $postId, $fieldName, $httpMethod);

            if ($field === null) {
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
     * Resolve a field name against the destination resource.
     *
     * Updates resolve against the target post id and creates against the
     * registered route post type; `acf_get_field_groups()` applies the ACF
     * location rules for that resource in both cases. Only REST exposed
     * groups are considered, only fields of a REST exposed field type pass
     * (`show_in_rest` on the field type object), and the per group field set
     * is run through the `acf/rest/get_fields` filter with the resource type,
     * sub type, id and the request method, exactly as ACF does before it
     * exposes or accepts field data.
     *
     * The name must exist exactly once among the eligible top-level fields;
     * anything else (missing, hidden, filtered out, ambiguous, or located
     * elsewhere) resolves to null. The single eligible field is returned as
     * stored, keeping its exact field key and parent identity.
     */
    private function resolveDestinationField(string $postType, ?int $postId, string $fieldName, string $httpMethod): ?array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')
            || !function_exists('acf_get_field_type')) {
            return null;
        }

        // Mirrors ACF's REST lookup: updates locate groups on the concrete
        // post, creates on the post type.
        $groups = $postId !== null
            ? acf_get_field_groups(['post_id' => $postId])
            : acf_get_field_groups(['post_type' => $postType]);

        $resource = [
            'type' => 'post',
            'sub_type' => $postType,
            'id' => $postId,
        ];

        $eligible = [];

        foreach ((array) $groups as $group) {
            if (!is_array($group) || empty($group['show_in_rest']) || !is_string($group['key'] ?? null)) {
                continue;
            }

            foreach ($this->restExposedGroupFields((string) $group['key'], $resource, $httpMethod) as $field) {
                if (!is_array($field) || ($field['name'] ?? null) !== $fieldName) {
                    continue;
                }

                if (!$this->isTopLevelGroupField($field, $group)) {
                    continue;
                }

                $eligible[] = $field;
            }
        }

        if (count($eligible) !== 1) {
            return null;
        }

        return $eligible[0];
    }

    /**
     * The REST exposed fields of a field group, exactly as ACF exposes them.
     *
     * Field types may hide themselves through their `show_in_rest` property
     * and third parties may narrow the result through `acf/rest/get_fields`,
     * which receives the resource context and the HTTP method.
     *
     * @param array{type: string, sub_type: string, id: int|null} $resource
     *
     * @return list<array<string, mixed>>
     */
    private function restExposedGroupFields(string $groupKey, array $resource, string $httpMethod): array
    {
        $fields = [];

        foreach ((array) acf_get_fields($groupKey) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldType = acf_get_field_type((string) ($field['type'] ?? ''));

            if (!is_object($fieldType) || empty($fieldType->show_in_rest)) {
                continue;
            }

            $fields[] = $field;
        }

        /**
         * Filter the fields available to the REST API, as documented by ACF.
         *
         * @param array<string, mixed> $fields     The REST exposed fields of this field group.
         * @param array                $resource   Contextual information about the current resource request.
         * @param string               $httpMethod The HTTP method of the current request.
         */
        $fields = apply_filters('acf/rest/get_fields', $fields, $resource, $httpMethod);

        return array_values(array_filter((array) $fields, 'is_array'));
    }

    /**
     * Whether a field is a top-level field of the given field group.
     *
     * Exported groups record the group key as parent; database stored groups
     * record the numeric group post id, so both identities are accepted.
     * Nested sub fields belong to their parent field and are never eligible.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $group
     */
    private function isTopLevelGroupField(array $field, array $group): bool
    {
        $parent = $field['parent'] ?? null;

        if ($parent === $group['key']) {
            return true;
        }

        $groupId = is_numeric($group['ID'] ?? null) ? (int) $group['ID'] : 0;

        return $groupId > 0 && is_numeric($parent) && (int) $parent === $groupId;
    }

    /**
     * Whether a reference path uses the canonical shape for the field type.
     *
     * Image and file fields accept exactly the top-level field name. Gallery
     * fields accept the field name followed by a single numeric index, which
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
        return $this->error(
            'acf_rest_upload_invalid_reference',
            400,
            'The uploaded file is not attached to an upload enabled ACF field.'
        );
    }

    /**
     * Whether the request targets a supported route and method, resolving the
     * destination post type and target post.
     *
     * @return array{postType: string, targetPostId: int|null, isCreate: bool}|null
     */
    private function resolveRouteTarget(WP_REST_Request $request): ?array
    {
        $route = (string) $request->get_route();

        if (!in_array(strtoupper((string) $request->get_method()), self::METHODS, true)) {
            return null;
        }

        foreach (self::ROUTES as $prefix => $postType) {
            if ($route === $prefix) {
                return ['postType' => $postType, 'targetPostId' => null, 'isCreate' => true];
            }

            if (str_starts_with($route, $prefix . '/')) {
                $suffix = substr($route, strlen($prefix) + 1);

                if ($suffix !== '' && ctype_digit($suffix)) {
                    return ['postType' => $postType, 'targetPostId' => (int) $suffix, 'isCreate' => false];
                }
            }
        }

        return null;
    }

    /**
     * Flatten the uploaded file parts into a map keyed by file reference key.
     *
     * The wire contract only allows exactly one nesting level:
     * `_acf_rest_files[<key>]`. Deeper structures would silently merge or
     * shadow keys, so they are rejected instead.
     *
     * @param array<array-key, mixed> $fileParams
     *
     * @return array<string, array{name:string, type:string, tmp_name:string, error:int, size:int}>
     *
     * @throws InvalidRequestException When the parts are structurally conflicting.
     */
    private function flattenUploadedFiles(array $fileParams): array
    {
        $files = $fileParams[self::FILES_FIELD] ?? null;

        if ($files === null) {
            return [];
        }

        if (!is_array($files)) {
            throw InvalidRequestException::invalidRequest(
                sprintf('The reserved part "%s" must be a map of file parts.', self::FILES_FIELD)
            );
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
     *
     * @throws InvalidRequestException When a part nests deeper than one key.
     */
    private function flattenNativeFiles(mixed $names, array $files, array $path, array &$records): void
    {
        if (is_array($names)) {
            if ($path !== []) {
                throw InvalidRequestException::invalidRequest(
                    sprintf(
                        'The file part "%s" nests deeper than "%s[<key>]".',
                        self::FILES_FIELD . '[' . implode('][', array_map('strval', $path)) . ']',
                        self::FILES_FIELD
                    )
                );
            }

            foreach ($names as $key => $child) {
                $this->flattenNativeFiles($child, $files, [...$path, $key], $records);
            }

            return;
        }

        if ($path === [] || !is_string($names) || $names === '') {
            return;
        }

        // Numeric part names ("_acf_rest_files[0]") arrive as int array keys
        // in the native layout; normalize them back to string keys.
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
     *
     * @throws InvalidRequestException When a record key would contain brackets.
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

            if (str_contains($childPath, '[') || str_contains($childPath, ']')) {
                throw InvalidRequestException::invalidRequest(
                    sprintf('The file part key "%s" nests deeper than "%s[<key>]".', $childPath, self::FILES_FIELD)
                );
            }

            $this->flattenFileRecords($child, $childPath, $records);
        }
    }

    /**
     * Write a value at a bracket path inside the ACF payload.
     *
     * @param array<mixed>           $values
     * @param list<int|string> $segments
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
     * Canonicalize numeric-string attachment ids inside a gallery value while
     * preserving keys and order.
     *
     * @return mixed
     */
    private function coerceGalleryIdList(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (is_string($entry) && preg_match('/^\d+$/', $entry) === 1 && (string) (int) $entry === $entry) {
                $result[$key] = (int) $entry;

                continue;
            }

            $result[$key] = $entry;
        }

        return $result;
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
     * Build the retryable in-progress response for a held idempotency key.
     *
     * 425 (Too Early) is used because protocol senders treat it as retryable
     * and honor Retry-After; a plain 409 would not be retried.
     */
    private function inProgressResponse(int $expiresAt): WP_REST_Response
    {
        $retryAfter = max(1, min($expiresAt - time(), self::ACTIVE_LOCK_TTL));

        $response = new WP_REST_Response([
            'code' => 'acf_rest_upload_in_progress',
            'message' => 'A request with this idempotency key is already being processed.',
            'data' => ['status' => 425],
        ], 425);

        $response->header('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * Build the retryable response for an operation without completion proof.
     *
     * Same retryable contract as the in-progress response (425 with
     * Retry-After). The unresolved identity or active claim blocks fresh
     * creation. Retrying does not automatically resume interrupted saving.
     */
    private function recoveryPendingResponse(): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code' => 'acf_rest_upload_recovery_pending',
            'message' => 'The recorded resource of this idempotency key is not confirmed yet. Retry with the same key.',
            'data' => ['status' => 425],
        ], 425);

        $response->header('Retry-After', (string) self::ACTIVE_LOCK_TTL);

        return $response;
    }

    /**
     * Build the replay response for a completed claim.
     *
     * The status mirrors what the native endpoint would have returned for the
     * original operation (201 for a create, 200 for an update).
     */
    private function replayResponse(?int $postId, bool $isCreate, int $contextId): WP_REST_Response
    {
        $this->markFinalized($contextId);

        return new WP_REST_Response(['id' => $postId], $isCreate ? 201 : 200);
    }

    /**
     * Build a standard REST error payload with a controlled status code.
     */
    private function error(string $code, int $status, string $message): WP_Error
    {
        return new WP_Error($code, __($message, 'api-sponsor-manager'), ['status' => $status]);
    }

    /**
     * Classify a media library upload failure into a controlled client/server
     * error, keeping the original message.
     */
    private function classifyUploadError(WP_Error $uploadError): WP_Error
    {
        $message = $uploadError->get_error_message();
        $lowered = strtolower($message);

        if (str_contains($lowered, 'could not be moved')) {
            return $this->error('acf_rest_upload_move_failed', 500, $message);
        }

        if (str_contains($lowered, 'file type') || str_contains($lowered, 'not permitted for security')) {
            return $this->error('acf_rest_upload_invalid_file_type', 415, $message);
        }

        return $this->error('acf_rest_upload_invalid_file', 400, $message);
    }

    /**
     * Validate the client generated idempotency key.
     */
    private function validateIdempotencyKey(WP_REST_Request $request): ?WP_Error
    {
        $header = $request->get_header(self::IDEMPOTENCY_HEADER);

        if (!is_string($header) || trim($header) === '') {
            return $this->error(
                'acf_rest_upload_missing_idempotency_key',
                400,
                'The Idempotency-Key header is required for ACF REST uploads.'
            );
        }

        if (!$this->isValidIdempotencyKey(trim($header))) {
            return $this->error(
                'acf_rest_upload_invalid_idempotency_key',
                400,
                'The Idempotency-Key header must be a UUID.'
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

    /**
     * The idempotency state store.
     */
    protected function idempotencyStore(): IdempotencyStore
    {
        return $this->idempotencyStore ??= new IdempotencyStore();
    }
}
