<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use ApiSponsorManager\Notifications;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use Brain\Monkey\Functions;
use Mockery;
use PluginTestCase\PluginTestCase;
use WP_Post;
use WP_REST_Request;

class NotificationsTest extends PluginTestCase
{
    private Notifications $notifications;
    private NotificationRecorder $mail;
    private array $rows = [];
    private array $posts = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->mail = new NotificationRecorder();
        $this->notifications = $this->makeNotifications();
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_option')->alias(fn (string $key) => $this->rows[$key] ?? false);
        Functions\when('wp_cache_delete')->justReturn(true);
        $GLOBALS['wpdb'] = new class($this->rows) {
            public string $options = 'wp_options';
            public function __construct(private array &$rows) {}
            public function prepare(string $sql, mixed ...$args): string { return json_encode($args); }
            public function query(string $prepared): int {
                [$new, $key, $old] = json_decode($prepared, true);
                if (serialize($this->rows[$key] ?? false) !== $old) { return 0; }
                $this->rows[$key] = unserialize($new);
                return 1;
            }
        };
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function makeNotifications(): Notifications
    {
        $wp = Mockery::mock('WpService\Contracts\AddAction, WpService\Contracts\GetEditPostLink, WpService\Contracts\__');
        $acf = Mockery::mock('AcfService\Contracts\GetField, AcfService\Contracts\GetFields');
        $acf->shouldReceive('getField')->with('notification_email_templates', 'options')->andReturn([
            ['trigger' => 'submit', 'post_type' => 'offering', 'recipients' => '{email}', 'subject' => 'Submitted {ID}', 'message' => 'Saved'],
            ['trigger' => 'publish', 'post_type' => 'offering', 'recipients' => '{email}', 'subject' => 'Published {ID}', 'message' => 'Saved'],
        ]);
        $acf->shouldReceive('getFields')->andReturnUsing(static fn (int $id) => ['email' => $id . '@example.test']);
        return new Notifications($wp, $acf, $this->mail);
    }

    private function post(int $id): WP_Post
    {
        return $this->posts[$id] = new WP_Post(['ID' => $id, 'post_type' => 'offering', 'post_status' => 'draft']);
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

    public function testVersionTwoCreateSendsOnlyAfterItsCompletionAction(): void
    {
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload-Version', '2');
        $this->notifications->beforeRestCallbacks(null, [], $request);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        self::assertSame([], $this->mail->sent);
        $this->notifications->completedCreate(77, null, $request);
        $this->notifications->completedCreate(77, null, $request);
        self::assertSame([['77@example.test']], array_column($this->mail->sent, 'recipients'));
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

    private function begin(string $operation): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $this->rows[$operation] = ['status' => 'active', 'owner' => 'owner'];
        $this->notifications->beforeRestCallbacks(null, [], $request);
        $this->notifications->beginOperation($operation, 'owner', $request);
        return $request;
    }

    private function complete(string $operation, int $postId, WP_REST_Request $request): void
    {
        $this->rows[$operation]['status'] = 'complete';
        $this->rows[$operation]['post_id'] = $postId;
        $this->notifications->completeOperation($postId, $operation);
        $this->notifications->endOperation($operation, $request);
    }

    public function testTwoInternalOperationsSendOnlyTheirOwnRecipientsOnce(): void
    {
        $outer = $this->begin('outer');
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $inner = $this->begin('inner');
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        self::assertSame([], $this->mail->sent);
        $this->complete('inner', 88, $inner);
        self::assertSame([['88@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->complete('outer', 77, $outer);
        self::assertSame([['88@example.test'], ['77@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->notifications->completeOperation(77, 'outer');
        $this->makeNotifications()->completeOperation(88, 'inner');
        self::assertCount(2, $this->mail->sent, 'Repeated callbacks and a new process respect the durable claim.');
        self::assertTrue($this->rows['outer']['delivery_attempted']['sponsor_notifications']);
    }

    public function testFailedPublishThenSuccessfulSubmissionSendsOnlyTheSuccessfulEmail(): void
    {
        $failed = $this->begin('failed');
        $this->notifications->onPublish('publish', 'draft', $this->post(77));
        self::assertSame([], $this->mail->sent, 'Publish mail must wait for full success.');
        $this->notifications->endOperation('failed', $failed);
        self::assertSame([], $this->rows['failed']['completion_data']['sponsor_notifications']);
        $request = $this->begin('success');
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->complete('success', 88, $request);
        self::assertSame([['88@example.test']], array_column($this->mail->sent, 'recipients'));
        $this->makeNotifications()->completeOperation(77, 'failed');
        self::assertCount(1, $this->mail->sent, 'An incomplete operation cannot claim delivery.');
    }

    public function testNewProcessCanReadPersistedTemplatesAfterCompletedSaving(): void
    {
        $this->begin('saved');
        $this->notifications->onPublish('publish', 'draft', $this->post(77));
        $this->rows['saved']['status'] = 'complete';
        $this->rows['saved']['post_id'] = 77;
        $fresh = $this->makeNotifications();
        $fresh->completeOperation(77, 'saved');
        $fresh->completeOperation(77, 'saved');
        self::assertSame([['recipients' => ['77@example.test'], 'subject' => 'Published 77', 'message' => 'Saved']], $this->mail->sent);
    }

    public function testLostOwnershipPreventsNotificationStateAndReportsFailure(): void
    {
        $request = $this->begin('lost');
        $this->rows['lost']['owner'] = 'other-owner';
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        self::assertInstanceOf(\WP_Error::class, $this->notifications->afterRestCallbacks(null, [], $request));
        self::assertArrayNotHasKey('completion_data', $this->rows['lost']);
        self::assertSame([], $this->mail->sent);
    }

    public function testConcurrentDeliveryClaimCannotSendTheObservedBatch(): void
    {
        $this->begin('race');
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->rows['race']['status'] = 'complete';
        $this->rows['race']['post_id'] = 77;
        $reads = 0;
        Functions\when('get_option')->alias(function (string $key) use (&$reads) {
            if (++$reads === 2) {
                $this->rows[$key]['delivery_attempted']['sponsor_notifications'] = true;
                unset($this->rows[$key]['completion_data']['sponsor_notifications']);
            }
            return $this->rows[$key];
        });
        $this->notifications->completeOperation(77, 'race');
        self::assertSame([], $this->mail->sent);
    }

    public function testCrashAfterDeliveryClaimDoesNotRetryTheBatch(): void
    {
        $this->begin('crash');
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->rows['crash']['status'] = 'complete';
        $this->rows['crash']['post_id'] = 77;
        $this->mail->throwOnSend = true;
        try {
            $this->notifications->completeOperation(77, 'crash');
            self::fail('The fixture must interrupt delivery.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Interrupted delivery', $exception->getMessage());
        }
        $this->mail->throwOnSend = false;
        $this->makeNotifications()->completeOperation(77, 'crash');
        self::assertSame([], $this->mail->sent);
        self::assertTrue($this->rows['crash']['delivery_attempted']['sponsor_notifications']);
    }

    public function testNestedNonProtocolRestRequestDoesNotUseTheOuterOperation(): void
    {
        $outer = $this->begin('outer');
        $nested = new WP_REST_Request('POST', '/wp/v2/posts');
        $this->notifications->beforeRestCallbacks(null, [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(88));
        $this->notifications->afterRestCallbacks(null, [], $nested);
        $this->notifications->onSubmitted('draft', 'new', $this->post(77));
        $this->complete('outer', 77, $outer);
        self::assertSame([['77@example.test']], array_column($this->mail->sent, 'recipients'));
        self::assertArrayNotHasKey('sponsor_notifications', $this->rows['outer']['completion_data']);
    }
}

final class NotificationRecorder implements NotificationService
{
    public array $sent = [];
    public bool $throwOnSend = false;
    private array $current = [];
    public function setRecipients(array $recipients): void { $this->current['recipients'] = $recipients; }
    public function setSubject(string $subject): void { $this->current['subject'] = $subject; }
    public function setMessage(string $message): void { $this->current['message'] = $message; }
    public function send(): void {
        if ($this->throwOnSend) { throw new \RuntimeException('Interrupted delivery'); }
        $this->sent[] = $this->current;
    }
}
