<?php
$root = getenv('WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Set WP_ROOT to a disposable WordPress install with MyAduan active.\n");
    exit(1);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
if (!class_exists(\MyAduan\Plugin::class)) {
    fwrite(STDERR, "MyAduan plugin is not active in WP_ROOT.\n");
    exit(1);
}
require __DIR__ . '/Integration/TestCase.php';
