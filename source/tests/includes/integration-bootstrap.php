<?php

declare(strict_types=1);

if (getenv('SPONSOR_INTEGRATION_TESTS') !== '1') {
    throw new RuntimeException('Integration tests require SPONSOR_INTEGRATION_TESTS=1 and a disposable database.');
}
if (!getenv('WP_TESTS_DIR')) {
    putenv('WP_TESTS_DIR=' . dirname(__DIR__, 3) . '/vendor/wp-phpunit/wp-phpunit');
}
require __DIR__ . '/bootstrap.php';
