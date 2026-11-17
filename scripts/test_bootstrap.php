<?php

define('WPINC', 'wp-includes');

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugins_url($path, $file) { return 'https://example.test/plugin'; }
function plugin_basename($file) { return basename($file); }
function load_plugin_textdomain(...$args) {}
function add_action(...$args) { $GLOBALS['hooks'][] = $args[0]; return true; }
function add_filter(...$args) { $GLOBALS['hooks'][] = $args[0]; return true; }

if (isset($argv[2])) {
    require $argv[2];
}
require $argv[1];

if (!in_array('acf/init', $GLOBALS['hooks'] ?? [], true) ||
    !in_array('rest_pre_dispatch', $GLOBALS['hooks'] ?? [], true)) {
    throw new RuntimeException('Plugin did not register its initialization hooks.');
}
