<?php

namespace ApiSponsorManager\Helper\NotificationServices;

class FakeNotificationService implements NotificationService
{
    public array $recipientEmails = [];
    private string $subject;
    private string $message;

    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function setRecipients(array $recipients): void
    {
        $this->recipientEmails = $recipients;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function send(): void
    {
       error_log(var_export($this, true));
    }
}
