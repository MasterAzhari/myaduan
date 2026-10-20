<?php
$root = getenv('WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "Set WP_ROOT to a disposable WordPress install with MyAduan active.\n");
    exit(1);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('WP_USE_THEMES', false);
// PHPUnit includes this file inside a method, so WordPress' file-scope globals must be declared.
global $shortcode_tags, $wp_embed, $wp_rewrite, $wp_query, $wp_the_query, $wp_roles, $wp_locale, $wp_widget_factory,
    $wp, $wp_version, $wp_db_version, $table_prefix, $wpdb, $wp_filter, $wp_actions, $wp_current_filter, $wp_scripts, $wp_styles,
    $wp_plugin_paths, $allowedposttags, $allowedtags, $allowedentitynames;
require $root . '/wp-load.php';
if (!class_exists(\MyAduan\Plugin::class)) {
    fwrite(STDERR, "MyAduan plugin is not active in WP_ROOT.\n");
    exit(1);
}
require __DIR__ . '/Integration/TestCase.php';
