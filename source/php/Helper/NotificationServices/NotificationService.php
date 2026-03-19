<?php

namespace ApiSponsorManager\Helper\NotificationServices;

interface NotificationService
{
    public function send(): void;
    public function setRecipients(array $recipients): void;
    public function setSubject(string $subject): void;
    public function setMessage(string $message): void;
}
