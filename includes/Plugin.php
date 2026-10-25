<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Wires services together and registers WordPress hooks. */
final class Plugin
{
    private static ?Cases $cases = null;
    private static ?Crypto $crypto = null;

    public static function boot(): void
    {
        Installer::maybe_upgrade();
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_notices', [self::class, 'health_notice']);
        Settings::register();
        Mailer::register();
        Notifications::register();
        Frontend::register();
        Portal::register();
        Import::register();
        Home::register();
        add_filter('wp_authenticate_user', [Users::class, 'block_inactive']);
    }

    /** Warns site administrators about setup problems that put personal data at risk. */
    public static function health_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $problems = [];
        if (Storage::is_public()) {
            $problems[] = 'Folder simpanan MyAduan berada dalam public_html. Tetapkan MYADUAN_STORAGE_DIR ke folder di luar public_html.';
        }
        try {
            Keys::load();
        } catch (\RuntimeException $e) {
            $problems[] = $e->getMessage();
        }
        foreach ($problems as $p) {
            echo '<div class="notice notice-error"><p><strong>MyAduan:</strong> ' . esc_html($p) . '</p></div>';
        }
    }

    public static function register_routes(): void
    {
        (new Rest\PublicController(self::cases()))->register();
        (new Rest\AdminController(self::cases()))->register();
    }

    public static function crypto(): Crypto
    {
        return self::$crypto ??= new Crypto(Keys::load());
    }

    public static function cases(): Cases
    {
        return self::$cases ??= new Cases($GLOBALS['wpdb'], self::crypto(), new Catalog());
    }

    /** Test seam: lets integration tests reset cached services. */
    public static function reset(): void
    {
        self::$cases = null;
        self::$crypto = null;
    }
}
