<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for both suites.
 *
 * - Unit suite (source/tests/php): standalone. WordPress classes are provided
 *   by WordPressStubs.php and WordPress functions by Brain Monkey.
 * - Integration suite (source/tests/integration): requires a WordPress test
 *   library. Point WP_TESTS_DIR at a wordpress-tests checkout (with a
 *   configured wp-tests-config.php) and optionally ACF_PLUGIN_FILE at the
 *   ACF PRO entry file; this bootstrap then loads WordPress and the plugin
 *   exactly like a normal WordPress test bootstrap would.
 *
 * Example:
 *   export WP_TESTS_DIR=/srv/wordpress-tests/lib
 *   export ACF_PLUGIN_FILE=/srv/wp-content/plugins/advanced-custom-fields-pro/acf.php
 *   vendor/bin/phpunit --testsuite integration
 */

$wpTestsDir = getenv('WP_TESTS_DIR');
$wpTestsDir = is_string($wpTestsDir) && $wpTestsDir !== '' ? rtrim($wpTestsDir, '/') : null;

if ($wpTestsDir === null) {
    // Get around direct access blockers in the standalone suite.
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../../../');
    }
}

define('API_SPONSOR_MANAGER_PATH', __DIR__ . '/../../../');
define('API_SPONSOR_MANAGER_URL', 'https://example.com/wp-content/plugins/' . 'modularity-api-sponsor-manager');
define('API_SPONSOR_MANAGER_TEMPLATE_PATH', API_SPONSOR_MANAGER_PATH . 'templates/');

// Register the autoloader
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../php/');
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../integration/');

if ($wpTestsDir !== null) {
    // WordPress integration suite: boot core, activate ACF (when configured)
    // and this plugin, then let the WordPress test bootstrap take over.
    require_once $wpTestsDir . '/includes/functions.php';

    tests_add_filter('muplugins_loaded', static function (): void {
        $acfPluginFile = getenv('ACF_PLUGIN_FILE');

        if (is_string($acfPluginFile) && $acfPluginFile !== '' && file_exists($acfPluginFile)) {
            require_once $acfPluginFile;
        }

        require_once dirname(__DIR__, 2) . '/api-sponsor-manager.php';
    });

    require_once $wpTestsDir . '/includes/bootstrap.php';
}

require_once __DIR__ . '/WordPressStubs.php';
require_once __DIR__ . '/PluginTestCase.php';
