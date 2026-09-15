<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\AcfService;
use ApiSponsorManager\UploadProvider;
use Mockery;
use PluginTestCase\PluginTestCase;
use WpService\WpService;

final class UploadProviderTest extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        foreach (['OBJECT', 'ARRAY_A', 'ARRAY_N'] as $format) {
            if (!defined($format)) { define($format, $format); }
        }
    }

    public function testExternalSelectionReceivesServicesAndBootsOnceAtInitTwenty(): void
    {
        $wp = Mockery::mock(WpService::class);
        $acf = Mockery::mock(AcfService::class);
        $callback = null;
        $calls = [];
        $wp->shouldReceive('addAction')->once()->with('init', Mockery::on(function ($value) use (&$callback): bool {
            $callback = $value;
            return is_callable($value);
        }), 20)->andReturn(true);
        $wp->shouldReceive('applyFilters')->once()->with('AcfRestUpload/provider', null)->andReturn([
            'api_version' => 1, 'protocol_versions' => [2],
            'boot' => static function ($actualWp, $actualAcf) use (&$calls): void { $calls[] = [$actualWp, $actualAcf]; },
        ]);
        $provider = new UploadProvider($wp, $acf);
        $provider->addHooks();
        $provider->addHooks();
        self::assertSame([], $calls);
        $callback();
        $callback();
        self::assertSame([[$wp, $acf]], $calls);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatibleProviders')]
    public function testIncompatibleProviderFailsClosedWithoutCallingBoot(mixed $descriptor): void
    {
        $wp = Mockery::mock(WpService::class);
        $callback = null;
        $wp->shouldReceive('applyFilters')->once()->with('AcfRestUpload/provider', null)->andReturn($descriptor);
        $wp->shouldReceive('applyFilters')->with('AcfRestUpload/destinations', [])->andReturn([
            '/wp/v2/sponsor-offerings' => ['post_type' => 'offering', 'image_field' => 'image'],
        ]);
        $wp->shouldReceive('addFilter')->once()->with('rest_pre_dispatch', Mockery::on(function ($value) use (&$callback): bool {
            $callback = $value;
            return is_callable($value);
        }), 20, 3)->andReturn(true);
        $provider = new UploadProvider($wp, Mockery::mock(AcfService::class));
        $log = tempnam(sys_get_temp_dir(), 'provider-log-');
        $previous = ini_set('error_log', $log);
        try {
            $provider->select();
            $provider->select();
            $diagnostic = file_get_contents($log);
            self::assertStringContainsString('AcfRestUpload: Incompatible provider. Expected API 1, HTTP protocol 2, and callable boot.', $diagnostic);
            self::assertSame(1, substr_count($diagnostic, 'AcfRestUpload:'));
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
        foreach (['/wp/v2/sponsor-offerings', '/wp/v2/sponsor-offerings/42'] as $route) {
            $request = new \WP_REST_Request('POST', $route);
            $request->set_header('X-ACF-Rest-Upload-Version', '2');
            $result = $callback(null, null, $request);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('acf_rest_upload_unavailable', $result->get_error_code());
            self::assertSame(503, $result->get_error_data()['status']);
        }
        foreach ([['/wp/v2/sponsor-offerings', null], ['/wp/v2/posts', '2'], ['/wp/v2/sponsor-offerings-extra', '2']] as [$route, $version]) {
            $request = new \WP_REST_Request('POST', $route);
            if ($version !== null) { $request->set_header('X-ACF-Rest-Upload-Version', $version); }
            $original = new \stdClass();
            self::assertSame($original, $callback($original, null, $request));
        }
    }

    public static function incompatibleProviders(): array
    {
        $boot = static function (): void { self::fail('An incompatible provider must not boot.'); };
        return [[false], [[]], [['api_version' => 2, 'protocol_versions' => [2], 'boot' => $boot]],
            [['api_version' => '1', 'protocol_versions' => [2], 'boot' => $boot]],
            [['api_version' => 1, 'protocol_versions' => [1], 'boot' => $boot]],
            [['api_version' => 1, 'protocol_versions' => ['2'], 'boot' => $boot]],
            [['api_version' => 1, 'protocol_versions' => 2, 'boot' => $boot]],
            [['api_version' => 1, 'protocol_versions' => [2], 'boot' => null]]];
    }
}
