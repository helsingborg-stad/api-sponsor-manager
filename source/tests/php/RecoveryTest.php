<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use ApiSponsorManager\AcfRestUpload\Recovery;
use Mockery;
use PluginTestCase\PluginTestCase;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WpService\WpService;

/**
 * Controlled request interfaces and the hourly cleanup decision only.
 * These tests never execute media, cron, or the native receiver.
 */
final class RecoveryTest extends PluginTestCase
{
    private WpService $wp;

    public function setUp(): void
    {
        parent::setUp();
        foreach (['OBJECT', 'ARRAY_A', 'ARRAY_N'] as $format) {
            if (!defined($format)) { define($format, $format); }
        }
        $this->wp = Mockery::mock(WpService::class);
    }

    private function request(): TestRestRequest
    {
        return new TestRestRequest('POST', '/wp/v2/posts');
    }

    /** @param list<array{int, string, string}> $marked */
    private function recordMarkers(array &$marked): void
    {
        $this->wp->shouldReceive('updatePostMeta')->andReturnUsing(
            static function (int $id, string $key, mixed $value) use (&$marked): bool {
                $marked[] = [$id, $key, (string) $value];
                return true;
            }
        );
    }

    private function attachment(int $id, int $parent = 0): WP_Post
    {
        $post = new WP_Post([]);
        $post->ID = $id;
        $post->post_type = 'attachment';
        $post->post_parent = $parent;
        return $post;
    }

    public function testBeginRefusesASecondContextForTheSameRequest(): void
    {
        $this->wp->shouldReceive('addFilter')->once()->andReturn(true);
        $this->wp->shouldReceive('removeFilter')->once()->andReturn(true);
        $recovery = new Recovery($this->wp);
        $request = $this->request();
        self::assertTrue($recovery->begin($request));
        self::assertFalse($recovery->begin($request));
        $recovery->release($request);
    }

    public function testBeginCapturesAttachmentsThroughTheScopedTokenHook(): void
    {
        $hook = null;
        $this->wp->shouldReceive('addFilter')->once()
            ->with('added_post_meta', Mockery::on(static function ($callback) use (&$hook): bool {
                $hook = $callback;
                return is_callable($callback);
            }), PHP_INT_MAX, 4)->andReturn(true);
        $this->wp->shouldReceive('removeFilter')->once()->andReturn(true);
        $marked = [];
        $this->recordMarkers($marked);
        $recovery = new Recovery($this->wp);
        $request = $this->request();
        self::assertTrue($recovery->begin($request));
        $token = null;
        $recovery->upload($request, static function (array $data) use (&$token): WP_Error {
            $token = $data['meta_input'][Recovery::OWNER] ?? null;
            return new WP_Error('native_failure', 'Controlled');
        });
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $token);
        $hook(1, 42, Recovery::OWNER, $token);
        // A different token, key, or attachment must never join this batch.
        $hook(2, 43, Recovery::OWNER, 'other-token');
        $hook(3, 44, '_other_owner', $token);
        self::assertTrue($recovery->markFailed($request));
        self::assertSame([[42, Recovery::CLEANUP, '1']], $marked);
        $recovery->release($request);
    }

    public function testUploadCapturesReturnedAttachmentsWithoutDuplicates(): void
    {
        $this->wp->shouldReceive('addFilter')->once()->andReturn(true);
        $this->wp->shouldReceive('removeFilter')->once()->andReturn(true);
        $marked = [];
        $this->recordMarkers($marked);
        $recovery = new Recovery($this->wp);
        $request = $this->request();
        self::assertTrue($recovery->begin($request));
        self::assertSame(7, $recovery->upload($request, static fn (array $data): int => 7));
        self::assertSame(7, $recovery->upload($request, static fn (array $data): int => 7));
        self::assertInstanceOf(WP_Error::class,
            $recovery->upload($request, static fn (array $data): WP_Error => new WP_Error('ignored', 'No attachment')));
        self::assertTrue($recovery->markFailed($request));
        self::assertSame([[7, Recovery::CLEANUP, '1']], $marked);
        $recovery->release($request);
    }

    public function testFailedMarkerWriteIsReported(): void
    {
        $this->wp->shouldReceive('addFilter')->once()->andReturn(true);
        $this->wp->shouldReceive('removeFilter')->once()->andReturn(true);
        $this->wp->shouldReceive('updatePostMeta')->once()->andThrow(new \RuntimeException('Private storage detail'));
        $recovery = new Recovery($this->wp);
        $request = $this->request();
        self::assertTrue($recovery->begin($request));
        self::assertSame(9, $recovery->upload($request, static fn (array $data): int => 9));
        self::assertFalse($recovery->markFailed($request));
        $recovery->release($request);
    }

    public function testReleaseRemovesOnlyItsOwnScopedHook(): void
    {
        $hook = null;
        $this->wp->shouldReceive('addFilter')->once()
            ->with('added_post_meta', Mockery::on(static function ($callback) use (&$hook): bool {
                $hook = $callback;
                return true;
            }), PHP_INT_MAX, 4)->andReturn(true);
        $this->wp->shouldReceive('removeFilter')->once()
            ->with('added_post_meta', Mockery::on(static function ($callback) use (&$hook): bool {
                return $callback === $hook;
            }), PHP_INT_MAX)->andReturn(true);
        $recovery = new Recovery($this->wp);
        $request = $this->request();
        self::assertTrue($recovery->begin($request));
        $recovery->release($request);
        $recovery->release($request);
    }

    public function testCleanupDeletesAMarkedAttachmentWithoutAParent(): void
    {
        $this->wp->shouldReceive('getPosts')->once()
            ->with(Mockery::on(static fn (array $args): bool => $args['post_type'] === 'attachment'
                && $args['post_parent'] === 0 && $args['meta_key'] === Recovery::CLEANUP && $args['meta_value'] === '1'
                && $args['fields'] === 'ids' && $args['posts_per_page'] === 20 && $args['orderby'] === 'rand'))
            ->andReturn([7]);
        $this->wp->shouldReceive('getPost')->with(7)->andReturn($this->attachment(7));
        $this->wp->shouldReceive('getPostMeta')->with(7, Recovery::CLEANUP, true)->andReturn('1');
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(7, true)->andReturn($this->attachment(7));
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupNeverDeletesAParentedAttachment(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andReturn([7]);
        $this->wp->shouldReceive('getPost')->with(7)->andReturn($this->attachment(7, 42));
        $this->wp->shouldNotReceive('getPostMeta');
        $this->wp->shouldNotReceive('wpDeleteAttachment');
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupNeverDeletesWhenTheMarkerChanged(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andReturn([7]);
        $this->wp->shouldReceive('getPost')->with(7)->andReturn($this->attachment(7));
        $this->wp->shouldReceive('getPostMeta')->with(7, Recovery::CLEANUP, true)->andReturn('');
        $this->wp->shouldNotReceive('wpDeleteAttachment');
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupFinishesWhenTheAttachmentIsAlreadyDeleted(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andReturn([7, 8]);
        $this->wp->shouldReceive('getPost')->with(7)->andReturnNull();
        $this->wp->shouldReceive('getPost')->with(8)->andReturn($this->attachment(8));
        $this->wp->shouldReceive('getPostMeta')->with(8, Recovery::CLEANUP, true)->andReturn('1');
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(8, true)->andReturn($this->attachment(8));
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupContinuesAfterANativeDeletionRefusal(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andReturn([7, 8]);
        $this->wp->shouldReceive('getPost')->andReturnUsing(fn (int $id): WP_Post => $this->attachment($id));
        $this->wp->shouldReceive('getPostMeta')->andReturn('1');
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(7, true)->andReturn(false);
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(8, true)->andReturn($this->attachment(8));
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupContinuesAfterAThrowingDeletion(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andReturn([7, 8]);
        $this->wp->shouldReceive('getPost')->andReturnUsing(fn (int $id): WP_Post => $this->attachment($id));
        $this->wp->shouldReceive('getPostMeta')->andReturn('1');
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(7, true)->andThrow(new \RuntimeException('Private deletion detail'));
        $this->wp->shouldReceive('wpDeleteAttachment')->once()->with(8, true)->andReturn($this->attachment(8));
        (new Recovery($this->wp))->cleanup();
    }

    public function testCleanupKeepsTheMarkerWhenTheQueryFails(): void
    {
        $this->wp->shouldReceive('getPosts')->once()->andThrow(new \RuntimeException('Private database detail'));
        $this->wp->shouldNotReceive('wpDeleteAttachment');
        (new Recovery($this->wp))->cleanup();
    }
}
