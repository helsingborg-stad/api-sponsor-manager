<?php

declare(strict_types=1);

// Get around direct access blockers in the standalone suite.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../../');
}

// Register the autoloader
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../php/');
require_once dirname(__DIR__, 3) . '/vendor/antecedent/patchwork/Patchwork.php';

define('API_SPONSOR_MANAGER_PATH', dirname(__DIR__, 3) . '/');
define('API_SPONSOR_MANAGER_URL', 'https://example.com/wp-content/plugins/api-sponsor-manager');
define('API_SPONSOR_MANAGER_TEMPLATE_PATH', API_SPONSOR_MANAGER_PATH . 'templates/');
require_once dirname(__DIR__, 3) . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
require_once __DIR__ . '/WordPressTestDoubles.php';
require_once __DIR__ . '/PluginTestCase.php';
