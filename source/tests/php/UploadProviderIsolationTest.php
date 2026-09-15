<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\AcfService;
use ApiSponsorManager\App;
use ApiSponsorManager\Helper\CronScheduler\CronSchedulerInterface;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PluginTestCase\PluginTestCase;
use WpService\WpService;

final class UploadProviderIsolationTest extends PluginTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('selections')]
    public function testHostSelectionWithoutLoadingEmbeddedFiles(bool $absent, bool $external): void
    {
        foreach (['OBJECT', 'ARRAY_A', 'ARRAY_N'] as $format) {
            if (!defined($format)) { define($format, $format); }
        }
        $source = dirname(__DIR__, 2) . '/php';
        $fixture = sys_get_temp_dir() . '/fzsw-provider-' . bin2hex(random_bytes(8));
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        if ($absent) {
            mkdir($fixture);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = substr($file->getPathname(), strlen($source) + 1);
                if (str_starts_with($relative, 'AcfRestUpload/')) { continue; }
                $target = $fixture . '/' . $relative;
                if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
                copy($file->getPathname(), $target);
            }
            $loader->setPsr4('ApiSponsorManager\\', $fixture);
            self::assertDirectoryDoesNotExist($fixture . '/AcfRestUpload');
        }
        $log = tempnam(sys_get_temp_dir(), 'fzsw-log-');
        $previous = ini_set('error_log', $log);
        try {
            $wp = Mockery::mock(WpService::class);
            $acf = Mockery::mock(AcfService::class);
            $scheduler = Mockery::mock(CronSchedulerInterface::class, Hookable::class);
            $scheduler->shouldReceive('addHooks')->once();
            $scheduler->shouldReceive('addEvent')->once();
            $hooks = [];
            $register = static function ($hook, $callback, $priority = 10, $args = 1) use (&$hooks): bool {
                $hooks[$hook][] = [$callback, $priority, $args];
                return true;
            };
            $wp->shouldReceive('addAction')->andReturnUsing($register);
            $wp->shouldReceive('addFilter')->andReturnUsing($register);
            $routes = [];
            $boots = 0;
            $descriptor = $external ? ['api_version' => 1, 'protocol_versions' => [2],
                'boot' => function ($actualWp, $actualAcf) use ($wp, $acf, &$hooks, &$routes, &$boots): void {
                    self::assertSame($wp, $actualWp);
                    self::assertSame($acf, $actualAcf);
                    $routes = $hooks['AcfRestUpload/destinations'][0][0]([]);
                    $boots++;
                }] : null;
            $wp->shouldReceive('applyFilters')->once()->with('AcfRestUpload/provider', null)->andReturn($descriptor);
            new App($wp, $acf, Mockery::mock(NotificationService::class), $scheduler);
            self::assertSame($absent ? realpath($fixture) . '/App.php' : $source . '/App.php', (new \ReflectionClass(App::class))->getFileName());
            self::assertSame(0, $boots);
            foreach ($hooks['init'] as [$callback, $priority]) {
                if ($priority === 20) { $callback(); $callback(); }
            }
            self::assertSame($external ? 1 : 0, $boots);
            if ($external) {
                self::assertSame([
                    '/wp/v2/sponsor-assignments' => ['post_type' => 'assignment', 'image_field' => 'image'],
                    '/wp/v2/sponsor-offerings' => ['post_type' => 'offering', 'image_field' => 'image'],
                ], $routes);
                self::assertSame('', file_get_contents($log));
            } else {
                self::assertStringContainsString('AcfRestUpload: Embedded provider files are unavailable.', file_get_contents($log));
                $wp->shouldReceive('applyFilters')->with('AcfRestUpload/destinations', [])->andReturn($hooks['AcfRestUpload/destinations'][0][0]([]));
                $request = new \WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
                $request->set_header('X-ACF-Rest-Upload-Version', '2');
                $response = $hooks['rest_pre_dispatch'][0][0](null, null, $request);
                self::assertSame('acf_rest_upload_unavailable', $response->get_error_code());
                self::assertSame(503, $response->get_error_data()['status']);
            }
            self::assertSame([], array_values(array_filter(get_included_files(), static fn ($file) => str_contains($file, '/AcfRestUpload/'))));
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
            $loader->setPsr4('ApiSponsorManager\\', $source);
            if ($absent) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($fixture);
            }
        }
    }

    public static function selections(): array
    {
        return ['external' => [false, true], 'external without embedded files' => [true, true], 'missing fallback' => [true, false]];
    }
}
