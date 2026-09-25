<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use ApiSponsorManager\Notifications;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use Mockery;
use PluginTestCase\PluginTestCase;
use WP_Error;
use WP_Post;

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
        $post = new WP_Post([]);
        $post->ID = $id;
        $post->post_type = 'offering';
        $post->post_status = 'draft';
        return $post;
    }

    private function request(bool $multipart = true): TestRestRequest
    {
        $request = new TestRestRequest('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload', $multipart ? 'true' : 'false');
        return $request;
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

    public function testNativeSuccessSendsTheQueuedNotificationOnce(): void
    {
        $request = $this->request();
        $this->notifications->beforeRestCallbacks(null, [], $request);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        self::assertSame([], $this->mail->sent, 'A marked create waits for the native result.');
        $this->notifications->afterRestCallbacks(new TestRestResponse(['id' => 77], 201), [], $request);
        self::assertSame([['77@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->afterRestCallbacks(new TestRestResponse(['id' => 77], 201), [], $request);
        self::assertCount(1, $this->mail->sent, 'Each queued notification is consumed at most once.');
    }

    public function testNativeErrorDiscardsOnlyItsExactRequestQueue(): void
    {
        $request = $this->request();
        $this->notifications->beforeRestCallbacks(null, [], $request);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->notifications->afterRestCallbacks(new WP_Error('native_error', 'Rejected', ['status' => 400]), [], $request);
        $this->notifications->completedCreate(77, $request);
        self::assertSame([], $this->mail->sent);
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(88);
        self::assertSame([['88@example.test']], array_column($this->mail->sent, 'recipients'));
    }

    public function testNestedNativeRequestKeepsItsNotificationOutsideTheMarkedQueue(): void
    {
        $outer = $this->request();
        $this->notifications->beforeRestCallbacks(null, [], $outer);
        $nested = $this->request(false);
        $this->notifications->beforeRestCallbacks(null, [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(88);
        self::assertSame([['88@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->afterRestCallbacks(new TestRestResponse(['id' => 88], 201), [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->notifications->sendEmailsAfterMetaHasBeenSaved(77);
        self::assertCount(1, $this->mail->sent, 'The outer create must still wait for its native result.');
        $this->notifications->afterRestCallbacks(new TestRestResponse(['id' => 77], 201), [], $outer);
        self::assertSame([['88@example.test'], ['77@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->afterRestCallbacks(new TestRestResponse(['id' => 77], 201), [], $outer);
        self::assertCount(2, $this->mail->sent);
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
