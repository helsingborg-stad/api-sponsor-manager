<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\AcfService;
use ApiSponsorManager\App;
use ApiSponsorManager\AcfRestUpload\CreateReceiver;
use ApiSponsorManager\AcfRestUpload\Recovery;
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
        $wp->shouldReceive('addFilter')->andReturn(true)->byDefault();
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

        $event = $scheduler->events[0];
        self::assertSame('daily', $event->getRecurrence());
        self::assertSame('sponsor_manager_delete_expired_posts_cron', $event->getHook());
        $callback = $event->getHookCallback();
        self::assertIsArray($callback);
        self::assertInstanceOf(DeleteExpiredPost::class, $callback[0]);
        self::assertSame('onCronEvent', $callback[1]);
        self::assertIsCallable($callback);
    }

    public function testConstructorRegistersTheHourlyCleanupOnTheInjectedScheduler(): void
    {
        $scheduler = new AppSchedulerRecorder();
        $this->makeApp($scheduler);
        self::assertCount(2, $scheduler->events);
        $event = $scheduler->events[1];
        self::assertSame('hourly', $event->getRecurrence());
        self::assertSame(Recovery::HOOK, $event->getHook());
        self::assertInstanceOf(Recovery::class, $event->getHookCallback()[0]);
        self::assertSame('cleanup', $event->getHookCallback()[1]);
        self::assertIsCallable($event->getHookCallback());
        // Do not execute the worker: deletion needs separately approved resources.
    }

    public function testConstructorBootsTheNativeReceiverWithoutProviderSelection(): void
    {
        $this->makeApp(new AppSchedulerRecorder(), static function ($wp): void {
            $wp->shouldNotReceive('applyFilters')->with('AcfRestUpload/provider', null);
            $wp->shouldReceive('addFilter')->once()->with('rest_pre_dispatch',
                Mockery::on(static fn ($callback) => $callback[0] instanceof CreateReceiver && is_callable($callback)), 20, 3)->andReturn(true);
        });
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
