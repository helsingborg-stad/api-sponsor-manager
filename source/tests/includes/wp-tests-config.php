<?php

// Only the isolated integration bootstrap loads this configuration.
$database = getenv('SPONSOR_TEST_DB_NAME') ?: 'sponsor_test_receiver';
$host = getenv('SPONSOR_TEST_DB_HOST');
if (getenv('SPONSOR_INTEGRATION_TESTS') !== '1' || !str_starts_with($database, 'sponsor_test_') || !$host) {
    throw new RuntimeException('Integration tests require an explicit test host and a sponsor_test_ database.');
}
define('ABSPATH', dirname(__DIR__, 3) . '/vendor/johnpbloch/wordpress-core/');
define('DB_NAME', $database);
define('DB_USER', getenv('SPONSOR_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('SPONSOR_TEST_DB_PASSWORD') ?: '');
define('DB_HOST', $host);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wptests_';
define('WP_TESTS_DOMAIN', 'sponsor.example.test');
define('WP_TESTS_EMAIL', 'admin@example.test');
define('WP_TESTS_TITLE', 'Isolated sponsor integration');
define('WP_PHP_BINARY', PHP_BINARY);
define('WP_DEBUG', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');
