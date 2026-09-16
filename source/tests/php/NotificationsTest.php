<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use ApiSponsorManager\Notifications;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use Mockery;
use PluginTestCase\PluginTestCase;
use WP_Post;
use WP_REST_Request;

class NotificationsTest extends PluginTestCase
{
    private Notifications $notifications;
    private NotificationRecorder $mail;

    public function setUp(): void
    {
        parent::setUp();
        $this->mail = new NotificationRecorder();
        $wp = Mockery::mock('WpService\Contracts\AddAction, WpService\Contracts\GetEditPostLink, WpService\Contracts\__');
        $acf = Mockery::mock('AcfService\Contracts\GetField, AcfService\Contracts\GetFields');
        $acf->shouldReceive('getField')->with('notification_email_templates', 'options')->andReturn([
            ['trigger' => 'submit', 'post_type' => 'offering', 'recipients' => '{email}', 'subject' => 'Submitted {ID}', 'message' => 'Saved'],
            ['trigger' => 'publish', 'post_type' => 'offering', 'recipients' => '{email}', 'subject' => 'Published {ID}', 'message' => 'Saved'],
        ]);
        $acf->shouldReceive('getFields')->andReturnUsing(static fn (int $id) => ['email' => $id . '@example.test']);
        $this->notifications = new Notifications($wp, $acf, $this->mail);
    }

    private function post(int $id): WP_Post
    {
        return new WP_Post((object) ['ID' => $id, 'post_type' => 'offering', 'post_status' => 'draft']);
    }

    public function testLocalCompletionConsumesOnlyItsOwnPostQueue(): void
    {
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(77);
        self::assertSame([['77@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(77);
        self::assertCount(1, $this->mail->sent);
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(88);
        self::assertSame([['77@example.test'], ['88@example.test']], array_column($this->mail->sent, 'recipients'));
    }

    public function testFailedVersionTwoCreateDropsItsQueuedNotification(): void
    {
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload-Version', '2');
        $this->notifications->beforeRestCallbacks(null, [], $request);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->notifications->afterRestPostDispatch(null, [], $request);
        $this->notifications->completedCreate(77, null, $request);
        self::assertSame([], $this->mail->sent);
    }

    public function testNestedNativeRequestKeepsItsNotificationOutsideTheVersionTwoQueue(): void
    {
        $outer = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $outer->set_header('X-ACF-Rest-Upload-Version', '2');
        $this->notifications->beforeRestCallbacks(null, [], $outer);
        $nested = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $this->notifications->beforeRestCallbacks(null, [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(88);
        self::assertSame([['88@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->afterRestCallbacks(null, [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(77);
        self::assertCount(1, $this->mail->sent, 'The outer create must still wait for completion.');
        $this->notifications->afterRestCallbacks(null, [], $outer);
        $this->notifications->completedCreate(77, null, $outer);
        self::assertSame([['88@example.test'], ['77@example.test']], array_column($this->mail->sent, 'recipients'));
    }
}

final class NotificationRecorder implements NotificationService
{
    public array $sent = [];
    private array $current = [];
    public function setRecipients(array $recipients): void { $this->current['recipients'] = $recipients; }
    public function setSubject(string $subject): void { $this->current['subject'] = $subject; }
    public function setMessage(string $message): void { $this->current['message'] = $message; }
    public function send(): void
    {
        $this->sent[] = $this->current;
    }
}
