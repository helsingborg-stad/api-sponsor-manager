<?php

declare(strict_types=1);

// Get around direct access blockers in the standalone suite.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../../');
}

// Register the autoloader
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('ApiSponsorManager\\Test\\', __DIR__ . '/../php/');

define('API_SPONSOR_MANAGER_PATH', dirname(__DIR__, 3) . '/');
define('API_SPONSOR_MANAGER_URL', 'https://example.com/wp-content/plugins/api-sponsor-manager');
define('API_SPONSOR_MANAGER_TEMPLATE_PATH', API_SPONSOR_MANAGER_PATH . 'templates/');
foreach (['class-wp-error.php', 'class-wp-post.php', 'class-wp-http-response.php',
    'rest-api/class-wp-rest-request.php', 'rest-api/class-wp-rest-response.php'] as $classFile) {
    require_once dirname(__DIR__, 3) . '/vendor/johnpbloch/wordpress-core/wp-includes/' . $classFile;
}
require_once __DIR__ . '/PluginTestCase.php';
