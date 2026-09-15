<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for both suites.
 *
 * - Unit suite (source/tests/php): standalone. WordPress classes are provided
 *   by WordPressStubs.php and WordPress functions by Brain Monkey.
 * - Integration suite (source/tests/integration): requires a WordPress test
 *   library. Point WP_TESTS_DIR at a wordpress-tests checkout (with a
 *   configured test database) and ACF_PLUGIN_FILE at the
 *   ACF PRO entry file; this bootstrap then loads WordPress and the plugin
 *   exactly like a normal WordPress test bootstrap would.
 *
 * Example:
 *   export WP_TESTS_DIR=/srv/wordpress-tests/lib
 *   export ACF_PLUGIN_FILE=/srv/wp-content/plugins/advanced-custom-fields-pro/acf.php
 *   composer test:integration
 */

$wpTestsDir = getenv('WP_TESTS_DIR');
$wpTestsDir = is_string($wpTestsDir) && $wpTestsDir !== '' ? rtrim($wpTestsDir, '/') : null;

if ($wpTestsDir === null) {
    // Get around direct access blockers in the standalone suite.
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../../../');
    }
}

// Register the autoloader
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../php/');
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../integration/');

if ($wpTestsDir !== null) {
    if (getenv('SPONSOR_INTEGRATION_TESTS') !== '1') {
        throw new RuntimeException('Set SPONSOR_INTEGRATION_TESTS=1 only for an isolated, disposable test database.');
    }
    $acfPluginFile = getenv('ACF_PLUGIN_FILE');
    if (!is_string($acfPluginFile) || !is_file($acfPluginFile)) {
        throw new RuntimeException('ACF_PLUGIN_FILE must name an isolated ACF PRO installation.');
    }
    define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__, 3) . '/vendor/yoast/phpunit-polyfills');
    require_once $wpTestsDir . '/includes/functions.php';

    tests_add_filter('muplugins_loaded', static function () use ($acfPluginFile): void {
        add_filter('pre_wp_mail', static fn () => true, PHP_INT_MAX);
        require_once $acfPluginFile;
        require_once dirname(__DIR__, 3) . '/api-sponsor-manager.php';
    });

    require_once $wpTestsDir . '/includes/bootstrap.php';
    require_once __DIR__ . '/NativeTestCase.php';
    require_once __DIR__ . '/PhpMultipartParser.php';
    return;
}

define('API_SPONSOR_MANAGER_PATH', dirname(__DIR__, 3) . '/');
define('API_SPONSOR_MANAGER_URL', 'https://example.com/wp-content/plugins/api-sponsor-manager');
define('API_SPONSOR_MANAGER_TEMPLATE_PATH', API_SPONSOR_MANAGER_PATH . 'templates/');
require_once __DIR__ . '/WordPressStubs.php';
require_once __DIR__ . '/PluginTestCase.php';
