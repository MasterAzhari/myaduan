<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin portal at /adminaduan/ (Settings → portal path).
 *
 * A standalone page outside the theme: WordPress login, then a small app that
 * talks to the REST API. MyAduan-only accounts land here after login and are
 * kept out of wp-admin.
 */
final class Portal
{
    public static function register(): void
    {
        add_action('parse_request', [self::class, 'maybe_serve'], 0);
        add_filter('login_redirect', [self::class, 'login_redirect'], 10, 3);
        add_action('admin_init', [self::class, 'keep_out_of_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'admin_bar']);
    }

    /** Whether a request path (relative to the site root) is the portal. */
    public static function matches(string $path): bool
    {
        $base = trim(Settings::get('portal_path'), '/');
        $path = trim($path, '/');
        return $base !== '' && ($path === $base || str_starts_with($path, $base . '/'));
    }

    /** Path of the current request relative to home_url(), so it works with any permalink setting. */
    public static function request_path(string $uri): string
    {
        $path = rawurldecode((string) wp_parse_url($uri, PHP_URL_PATH));
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = substr($path, strlen($home));
        }
        return trim($path, '/');
    }

    public static function maybe_serve(): void
    {
        if (!self::matches(self::request_path((string) ($_SERVER['REQUEST_URI'] ?? '')))) {
            return;
        }
        $r = self::response(wp_get_current_user());
        status_header($r['status']);
        nocache_headers();
        foreach ($r['headers'] as $name => $value) {
            header("$name: $value");
        }
        echo $r['body'];
        exit;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    public static function response(\WP_User $user): array
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ];
        if (!$user->exists()) {
            // The route after # never reaches the server, so the page adds it to redirect_to before going to login.
            return ['status' => 200, 'headers' => $headers, 'body' => self::login_page()];
        }
        if (!user_can($user, Access::CAP_ACCESS)) {
            return ['status' => 403, 'headers' => $headers, 'body' => self::denied_page()];
        }
        return ['status' => 200, 'headers' => $headers, 'body' => self::app_page($user)];
    }

    /** MyAduan-only accounts go straight to the portal after logging in. */
    public static function login_redirect(string $to, string $requested, mixed $user): string
    {
        if (!$user instanceof \WP_User || !self::portal_only($user)) {
            return $to;
        }
        $requested_path = $requested ? (string) wp_parse_url($requested, PHP_URL_PATH) : '';
        $wants_admin = $requested === '' || str_contains($requested_path, '/wp-admin');
        return $wants_admin ? Settings::portal_url() : $to;
    }

    public static function keep_out_of_wp_admin(): void
    {
        if (wp_doing_ajax() || wp_doing_cron() || !self::portal_only(wp_get_current_user())) {
            return;
        }
        // Their own profile (password, e-mail) stays reachable.
        if (($GLOBALS['pagenow'] ?? '') === 'profile.php') {
            return;
        }
        wp_safe_redirect(Settings::portal_url());
        exit;
    }

    public static function admin_bar(bool $show): bool
    {
        return $show && !self::portal_only(wp_get_current_user());
    }

    /** A MyAduan account with no other WordPress duties. */
    public static function portal_only(\WP_User $user): bool
    {
        return $user->exists() && user_can($user, Access::CAP_ACCESS) && !user_can($user, 'edit_posts') && !user_can($user, 'manage_options');
    }

    private static function app_page(\WP_User $user): string
    {
        $assets = plugins_url('assets/', MYADUAN_FILE);
        $config = [
            'api' => rest_url('myaduan/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'logout' => html_entity_decode(wp_logout_url(wp_login_url(Settings::portal_url()))),
            'profile' => admin_url('profile.php'),
            'home' => home_url('/'),
            'site' => get_bloginfo('name'),
            'version' => MYADUAN_VERSION,
        ];
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $ver = rawurlencode(MYADUAN_VERSION);
        return '<!doctype html><html lang="ms"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>Portal Admin MyAduan</title>'
            . '<link rel="stylesheet" href="' . esc_url($assets . 'admin.css?ver=' . $ver) . '">'
            . '<script type="application/json" id="myaduan-config">' . $json . '</script>'
            . '<script src="' . esc_url($assets . 'admin.js?ver=' . $ver) . '" defer></script>'
            . '</head><body><div id="app" aria-live="polite"><p class="boot">Memuatkan portal…</p></div>'
            . '<noscript><p class="boot">Sila aktifkan JavaScript untuk menggunakan portal MyAduan.</p></noscript>'
            . '</body></html>';
    }

    private static function login_page(): string
    {
        $assets = plugins_url('assets/', MYADUAN_FILE);
        $config = ['login' => wp_login_url(), 'portal' => Settings::portal_url()];
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<!doctype html><html lang="ms"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>Log masuk · MyAduan</title>'
            . '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url(wp_login_url(Settings::portal_url())) . '"></noscript>'
            . '<link rel="stylesheet" href="' . esc_url($assets . 'admin.css?ver=' . rawurlencode(MYADUAN_VERSION)) . '">'
            . '<script type="application/json" id="myaduan-config">' . $json . '</script>'
            . '<script src="' . esc_url($assets . 'admin.js?ver=' . rawurlencode(MYADUAN_VERSION)) . '" defer></script>'
            . '</head><body><div id="app"><p class="boot">Ke halaman log masuk…</p></div></body></html>';
    }

    private static function denied_page(): string
    {
        $assets = plugins_url('assets/', MYADUAN_FILE);
        return '<!doctype html><html lang="ms"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>Tiada akses · MyAduan</title>'
            . '<link rel="stylesheet" href="' . esc_url($assets . 'admin.css?ver=' . rawurlencode(MYADUAN_VERSION)) . '">'
            . '</head><body><main class="denied"><h1>Tiada akses</h1>'
            . '<p>Akaun ini tidak mempunyai akses ke portal MyAduan. Hubungi Super Admin jika anda perlukan akses.</p>'
            . '<p><a class="btn" href="' . esc_url(wp_logout_url(wp_login_url(Settings::portal_url()))) . '">Log keluar</a></p>'
            . '</main></body></html>';
    }
}
