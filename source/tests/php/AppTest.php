<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\AcfService;
use ApiSponsorManager\App;
use ApiSponsorManager\DeleteExpiredPost;
use ApiSponsorManager\Helper\CronScheduler\CronEventInterface;
use ApiSponsorManager\Helper\CronScheduler\CronSchedulerInterface;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use Mockery;
use PluginTestCase\PluginTestCase;
use WpService\WpService;

class AppTest extends PluginTestCase
{
    private function makeApp(AppSchedulerRecorder $scheduler, ?callable $configure = null): App
    {
        // WpService method defaults refer to WordPress result-format constants.
        foreach (['OBJECT', 'ARRAY_A', 'ARRAY_N'] as $format) {
            if (!defined($format)) {
                define($format, $format);
            }
        }
        $wp = Mockery::mock(WpService::class);
        $wp->shouldReceive('addAction')->andReturn(true)->byDefault();
        $wp->shouldReceive('addFilter')->once()
            ->with('AcfRestUpload/destinations', Mockery::type('callable'))->andReturn(true);
        if ($configure !== null) { $configure($wp); }

        return new App(
            $wp,
            Mockery::mock(AcfService::class),
            Mockery::mock(NotificationService::class),
            $scheduler
        );
    }

    public function testInitRegistersEachSuppliedHookableOnce(): void
    {
        $app = $this->makeApp(new AppSchedulerRecorder());
        $first = Mockery::mock(Hookable::class);
        $second = Mockery::mock(Hookable::class);
        $first->shouldReceive('addHooks')->once();
        $second->shouldReceive('addHooks')->once();

        $app->init($first, $second);
    }

    public function testConstructorInitializesTheScheduler(): void
    {
        $scheduler = new AppSchedulerRecorder();

        $this->makeApp($scheduler);

        self::assertSame(1, $scheduler->hookCalls);
    }

    public function testConstructorAddsTheDailyExpirationEvent(): void
    {
        $scheduler = new AppSchedulerRecorder();

        $this->makeApp($scheduler);

        self::assertCount(1, $scheduler->events);
        $event = $scheduler->events[0];
        self::assertSame('daily', $event->getRecurrence());
        self::assertSame('sponsor_manager_delete_expired_posts_cron', $event->getHook());
        $callback = $event->getHookCallback();
        self::assertIsArray($callback);
        self::assertInstanceOf(DeleteExpiredPost::class, $callback[0]);
        self::assertSame('onCronEvent', $callback[1]);
        self::assertIsCallable($callback);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('createUploadOptIn')]
    public function testCreateUploadHooksRequireExplicitOptIn(bool $enabled): void
    {
        $boot = null;
        $this->makeApp(new AppSchedulerRecorder(), static function ($wp) use ($enabled, &$boot): void {
            $wp->shouldReceive('addAction')->andReturnUsing(static function ($hook, $callback, $priority = 10) use (&$boot): bool {
                if ($hook === 'init' && $priority === 20) { $boot = $callback; }
                return true;
            });
            $wp->shouldReceive('applyFilters')->once()->with('ApiSponsorManager/enableCreateUploads', false)->andReturn($enabled);
            foreach (['rest_pre_dispatch' => [20, 3], 'rest_request_before_callbacks' => [10, 3],
                'rest_dispatch_request' => [10, 4], 'rest_request_after_callbacks' => [PHP_INT_MAX, 3]] as $hook => [$priority, $args]) {
                $wp->shouldReceive('addFilter')->times($enabled ? 1 : 0)
                    ->with($hook, Mockery::type('callable'), $priority, $args)->andReturn(true);
            }
        });
        self::assertIsCallable($boot);
        $boot();
    }

    public static function createUploadOptIn(): array
    {
        return [[false], [true]];
    }
}

final class AppSchedulerRecorder implements CronSchedulerInterface, Hookable
{
    public int $hookCalls = 0;

    /** @var list<CronEventInterface> */
    public array $events = [];

    public function addHooks(): void
    {
        $this->hookCalls++;
    }

    public function addEvent(CronEventInterface $cronEvent): void
    {
        $this->events[] = $cronEvent;
    }
}
