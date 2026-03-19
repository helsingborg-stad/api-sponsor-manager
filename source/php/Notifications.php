<?php

namespace ApiSponsorManager;

use AcfService\Contracts\GetField;
use AcfService\Contracts\GetFields;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use WP_Post;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;
use WpService\Contracts\GetEditPostLink;

class Notifications implements Hookable {
    private array $mailQueue = [];

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
                    $this->mailQueue[] = [$template, $post]; //store for later use when meta is avalible
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

    public function sendEmailsAfterMetaHasBeenSaved()
    {
        foreach ($this->mailQueue as $params) {
            $this->composeAndSendEmail(...$params);
        }
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('transition_post_status', [$this, 'onSubmitted'], 10, 3);
        $this->wpService->addAction('transition_post_status', [$this, 'onPublish'], 10, 3);
        $this->wpService->addAction('ModularityFrontendForm/afterInsertPost', [$this, 'sendEmailsAfterMetaHasBeenSaved'], 1, 0);
    }
}