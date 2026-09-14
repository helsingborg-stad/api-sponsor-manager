<?php

namespace ApiSponsorManager;

use AcfService\Contracts\GetField;
use AcfService\Contracts\GetFields;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use ApiSponsorManager\AcfRestUpload\IdempotencyStore;
use ApiSponsorManager\AcfRestUpload\Receiver;
use WP_Post;
use WP_REST_Request;
use WP_Error;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;
use WpService\Contracts\GetEditPostLink;

class Notifications implements Hookable {
    private array $mailQueue = [];
    private array $requests = [];
    private array $operationRequests = [];
    private array $operations = [];
    private const CONSUMER = 'sponsor_notifications';

    public function __construct(
        public AddAction&GetEditPostLink&__ $wpService, 
        public GetField&GetFields $acfService,
        public NotificationService $notificationService,
        ){}


    public function getEmailTemplates(): array
    {
        return $this->acfService->getField('notification_email_templates', 'options') ?? [];
    }

    public function replaceTemplateStr($subject, $replacements): string
    {
        $keys   = array_map(fn($k) => '{' . $k . '}', array_keys($replacements));
        $values = array_map(
            fn($v) => \is_array($v) ? \json_encode($v) : (string) $v, 
            array_values($replacements)
        );
        return str_replace($keys, $values, $subject);
    }

    public function composeAndSendEmail(array $template, WP_Post $post )
    {
        $acfFields = $this->acfService->getFields($post->ID, true);
        $templateData = [
            ...json_decode(json_encode($post), true),
            ...!empty($acfFields) ? $acfFields : []
        ];

        $this->notificationService->setRecipients(
            array_map('trim', explode(
                ',', 
                $this->replaceTemplateStr($template['recipients'], $templateData)
            ))
        );
        $this->notificationService->setSubject(
            $this->replaceTemplateStr($template['subject'], $templateData)
        );
        $this->notificationService->setMessage(
            $this->replaceTemplateStr($template['message'], $templateData)
        );
        $this->notificationService->send();
    }

    public function onSubmitted(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus === 'draft' && $oldStatus === 'new') {
            foreach ($this->getEmailTemplates() as $template) {
                if ($template['trigger'] === 'submit' && $post->post_type === $template['post_type']) {
                    if (!$this->queueForOperation($template, $post)) {
                        $this->mailQueue[$post->ID][] = [$template, $post];
                    }
                }
            }
        }
    }

    public function onPublish(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus === 'publish' && $oldStatus === 'draft') {
            foreach ($this->getEmailTemplates() as $template) {
                if ($template['trigger'] === 'publish' && $post->post_type === $template['post_type']) {
                    if (!$this->queueForOperation($template, $post)) {
                        $this->composeAndSendEmail($template, $post);
                    }
                }
            }
        }
    }

    public function sendEmailsAfterMetaHasBeenSaved(int $postId): void
    {
        $queue = $this->mailQueue[$postId] ?? [];
        unset($this->mailQueue[$postId]);
        foreach ($queue as $params) {
            $this->composeAndSendEmail(...$params);
        }
    }

    public function beforeRestCallbacks($response, $handler, WP_REST_Request $request): mixed
    {
        $this->requests[spl_object_id($request)] = $request;
        return $response;
    }

    public function afterRestCallbacks($response, $handler, WP_REST_Request $request): mixed
    {
        $id = spl_object_id($request);
        $operation = $this->operationRequests[$id] ?? null;
        if ($operation === null) {
            unset($this->requests[$id]);
        } elseif ($this->operations[$operation]['failed']) {
            return new WP_Error('acf_rest_upload_notification_state_failed', 'Could not persist notification state.', ['status' => 500]);
        }
        return $response;
    }

    public function beginOperation(string $operation, string $owner, WP_REST_Request $request): void
    {
        $id = spl_object_id($request);
        $this->requests[$id] = $request;
        $this->operationRequests[$id] = $operation;
        $this->operations[$operation] = ['owner' => $owner, 'posts' => [], 'failed' => false];
    }

    public function endOperation(string $operation, WP_REST_Request $request): void
    {
        if (isset($this->operations[$operation])) {
            // This only changes an active claim owned by this operation.
            // Completed batches were already consumed by their delivery claim.
            (new IdempotencyStore())->tryRecordCompletionData($operation, $this->operations[$operation]['owner'], self::CONSUMER, []);
        }
        unset($this->operations[$operation], $this->operationRequests[spl_object_id($request)], $this->requests[spl_object_id($request)]);
    }

    private function queueForOperation(array $template, WP_Post $post): bool
    {
        $operation = $this->operationRequests[array_key_last($this->requests)] ?? null;
        if ($operation === null) {
            return false;
        }
        $entry = &$this->operations[$operation];
        $entry['posts'][$post->ID][hash('sha256', serialize($template))] = $template;
        if (!(new IdempotencyStore())->tryRecordCompletionData($operation, $entry['owner'], self::CONSUMER, $entry['posts'])) {
            $entry['failed'] = true;
        }
        return true;
    }

    public function completeOperation(?int $postId, string $operation): void
    {
        $store = new IdempotencyStore();
        $state = $store->read($operation);
        $templates = $state['completion_data'][self::CONSUMER][$postId] ?? [];
        $post = $postId === null ? null : get_post($postId);
        if ($templates === [] || !$post instanceof WP_Post || $store->completedPostId($state) !== $postId
            || !$store->tryClaimDelivery($operation, self::CONSUMER, $state)) {
            return;
        }
        // The durable claim records an attempt, not confirmation of delivery.
        // A crash during this batch can lose its remaining messages.
        foreach ($templates as $template) {
            $this->composeAndSendEmail($template, $post);
        }
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('transition_post_status', [$this, 'onSubmitted'], 10, 3);
        $this->wpService->addAction('transition_post_status', [$this, 'onPublish'], 10, 3);
        $this->wpService->addAction('ModularityFrontendForm/afterInsertPost', [$this, 'sendEmailsAfterMetaHasBeenSaved'], 1, 1);

        add_filter('rest_request_before_callbacks', [$this, 'beforeRestCallbacks'], 1, 3);
        add_filter('rest_request_after_callbacks', [$this, 'afterRestCallbacks'], PHP_INT_MAX - 1, 3);
        add_filter('rest_post_dispatch', [$this, 'afterRestCallbacks'], PHP_INT_MAX - 1, 3);
        $this->wpService->addAction(Receiver::ACTION_BEGIN_OPERATION, [$this, 'beginOperation'], 1, 3);
        $this->wpService->addAction(Receiver::ACTION_END_OPERATION, [$this, 'endOperation'], 1, 2);
        $this->wpService->addAction(Receiver::ACTION_AFTER_INSERT, [$this, 'completeOperation'], 1, 2);
    }
}
