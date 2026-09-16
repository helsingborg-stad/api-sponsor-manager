<?php

namespace ApiSponsorManager;

use AcfService\Contracts\GetField;
use AcfService\Contracts\GetFields;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use WP_Post;
use WP_REST_Request;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;
use WpService\Contracts\GetEditPostLink;

class Notifications implements Hookable {
    private array $mailQueue = [];
    private array $requests = [];
    private array $createQueues = [];

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
                    if (!$this->queueForCreate($template, $post)) {
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
                    $this->composeAndSendEmail($template, $post);
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
        if ($request->get_header('X-ACF-Rest-Upload-Version') === '3') {
            // Register after receiver boot so this runs after its finalizer, including internal dispatch.
            add_filter('rest_request_after_callbacks', [$this, 'afterRestPostDispatch'], PHP_INT_MAX, 3);
        }
        return $response;
    }

    public function afterRestCallbacks($response, $handler, WP_REST_Request $request): mixed
    {
        unset($this->requests[spl_object_id($request)]);
        return $response;
    }

    public function afterRestPostDispatch($response, $handler, WP_REST_Request $request): mixed
    {
        unset($this->createQueues[spl_object_id($request)]);
        return $response;
    }

    public function completedCreate(int $postId, WP_REST_Request $request): void
    {
        $id = spl_object_id($request);
        $queue = $this->createQueues[$id][$postId] ?? [];
        unset($this->createQueues[$id][$postId]);
        foreach ($queue as $params) {
            $this->composeAndSendEmail(...$params);
        }
    }

    private function queueForCreate(array $template, WP_Post $post): bool
    {
        $id = array_key_last($this->requests);
        $request = $id === null ? null : $this->requests[$id];
        if (!$request instanceof WP_REST_Request || $request->get_header('X-ACF-Rest-Upload-Version') !== '3') {
            return false;
        }
        $this->createQueues[$id][$post->ID][] = [$template, $post];
        return true;
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('transition_post_status', [$this, 'onSubmitted'], 10, 3);
        $this->wpService->addAction('transition_post_status', [$this, 'onPublish'], 10, 3);
        $this->wpService->addAction('ModularityFrontendForm/afterInsertPost', [$this, 'sendEmailsAfterMetaHasBeenSaved'], 1, 1);

        add_filter('rest_request_before_callbacks', [$this, 'beforeRestCallbacks'], 1, 3);
        add_filter('rest_request_after_callbacks', [$this, 'afterRestCallbacks'], PHP_INT_MAX - 1, 3);
        add_filter('rest_post_dispatch', [$this, 'afterRestPostDispatch'], PHP_INT_MAX, 3);
        $this->wpService->addAction('ApiSponsorManager/uploadCreated', [$this, 'completedCreate'], 1, 2);
    }
}
