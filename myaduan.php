<?php
/**
 * Plugin Name:       MyAduan
 * Description:       Sistem aduan Parlimen Gombak: borang awam, semakan status, portal admin dan dashboard. Data disimpan dalam MySQL WordPress.
 * Version:           0.3.7
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Azhari
 * Text Domain:       myaduan
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MYADUAN_VERSION', '0.3.7');
define('MYADUAN_DB_VERSION', 1);
define('MYADUAN_FILE', __FILE__);
define('MYADUAN_DIR', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'MyAduan\\', 8) !== 0) {
        return;
    }
    $path = MYADUAN_DIR . '/includes/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

register_activation_hook(__FILE__, [MyAduan\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [MyAduan\Installer::class, 'deactivate']);

add_action('plugins_loaded', [MyAduan\Plugin::class, 'boot']);
