<?php

namespace ApiSponsorManager;

use PHPUnit\Framework\TestCase;

class ComposerIsolationTest extends TestCase
{
    public function testMagoStaysOnTheReleaseWithoutRuntimeHelperAutoloading(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

        // A broad range can install releases whose autoload.files collide with Municipio.
        self::assertSame('1.8.0', $manifest['require-dev']['carthage-software/mago']);
        $packages = array_column($lock['packages-dev'], null, 'name');
        self::assertSame('1.8.0', $packages['carthage-software/mago']['version']);
        self::assertEmpty($packages['carthage-software/mago']['autoload']['files'] ?? []);
    }

    public function testAutoloaderCanCoexistWithAnotherMagoInstallation(): void
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        // Use a fresh process: PHPUnit has already loaded this plugin's autoloader.
        $code = 'namespace Mago\\Internal { function locked() {} } namespace { require '
            . var_export($autoload, true) . '; print "loaded"; }';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(['loaded'], $output);
    }
}
