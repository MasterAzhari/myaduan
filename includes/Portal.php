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
        add_action('login_head', [self::class, 'login_brand']);
        add_filter('login_headerurl', static fn() => home_url('/'));
        add_filter('login_headertext', static fn() => 'MyAduan · Parlimen Gombak');
        // Staff ask the Super Admin for a new password; the e-mailed reset stays for site administrators.
        add_filter('lost_password_html_link', static fn(string $link): string => $link
            . '<br><span class="myaduan-lost">Staf MyAduan: lupa kata laluan? Minta Super Admin tetapkan kata laluan baru.</span>');
    }

    /** Officers log in through wp-login.php, so it shows the MyAduan logo instead of the WordPress one. */
    public static function login_brand(): void
    {
        echo '<link rel="icon" href="' . esc_url(Settings::icon_url()) . '">'
            . '<style>.login h1 a{background-image:url("' . esc_url(Settings::logo_url()) . '");background-size:contain;width:96px;height:96px}</style>' . "\n";
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
        $path = self::request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (!self::matches($path)) {
            return;
        }
        $file = substr($path, strlen(trim(Settings::get('portal_path'), '/')) + 1);
        if ($file === 'sw.js' || $file === 'manifest.webmanifest') {
            $r = $file === 'sw.js' ? self::service_worker() : self::manifest();
            status_header(200);
            header('Content-Type: ' . $r['type']);
            header('Cache-Control: no-cache');
            header('X-Content-Type-Options: nosniff');
            echo $r['body'];
            exit;
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
        // Their profile and password live in the portal too.
        wp_safe_redirect(Settings::portal_url() . (($GLOBALS['pagenow'] ?? '') === 'profile.php' ? '#/profil' : ''));
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

    /** @return array{type:string, body:string} */
    public static function service_worker(): array
    {
        $js = (string) file_get_contents(MYADUAN_DIR . '/assets/sw.js');
        return ['type' => 'application/javascript; charset=utf-8', 'body' => str_replace("'__ICON__'", wp_json_encode(plugins_url('assets/img/icon-192.png', MYADUAN_FILE)), $js)];
    }

    /** Lets staff install the portal on a phone ("Add to Home Screen"). */
    public static function manifest(): array
    {
        $portal = Settings::portal_url();
        $icon = static fn(int $size): array => ['src' => plugins_url("assets/img/icon-$size.png", MYADUAN_FILE), 'sizes' => "{$size}x$size", 'type' => 'image/png', 'purpose' => 'any maskable'];
        return ['type' => 'application/manifest+json', 'body' => (string) wp_json_encode([
            'name' => 'MyAduan Admin · Parlimen Gombak',
            'short_name' => 'MyAduan',
            'description' => 'Portal admin MyAduan: kes, tugasan dan notifikasi.',
            'id' => $portal,
            'start_url' => $portal,
            'scope' => $portal,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#b3122b',
            'lang' => 'ms',
            'icons' => [$icon(192), $icon(512)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /** Head tags that make the portal installable. */
    private static function pwa_head(): string
    {
        $base = Settings::portal_url();
        return '<link rel="manifest" href="' . esc_url($base . 'manifest.webmanifest') . '">'
            . '<meta name="theme-color" content="#b3122b">'
            . '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-title" content="MyAduan">'
            . '<link rel="apple-touch-icon" href="' . esc_url(plugins_url('assets/img/icon-192.png', MYADUAN_FILE)) . '">';
    }

    private static function app_page(\WP_User $user): string
    {
        $assets = plugins_url('assets/', MYADUAN_FILE);
        $config = [
            'api' => rest_url('myaduan/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'logout' => html_entity_decode(wp_logout_url(wp_login_url(Settings::portal_url()))),
            'home' => home_url('/'),
            'site' => get_bloginfo('name'),
            'version' => MYADUAN_VERSION,
            'staff_domain' => Settings::staff_domain(),
            'sw' => Settings::portal_url() . 'sw.js',
            'logo' => Settings::logo_url(),
        ];
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $ver = rawurlencode(MYADUAN_VERSION);
        return '<!doctype html><html lang="ms"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . self::icon_link()
            . self::pwa_head()
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
            . self::icon_link()
            . self::pwa_head()
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
            . self::icon_link()
            . '<title>Tiada akses · MyAduan</title>'
            . '<link rel="stylesheet" href="' . esc_url($assets . 'admin.css?ver=' . rawurlencode(MYADUAN_VERSION)) . '">'
            . '</head><body><main class="denied"><img class="denied-logo" src="' . esc_url(Settings::logo_url()) . '" alt="Logo MyAduan" width="72" height="72"><h1>Tiada akses</h1>'
            . '<p>Akaun ini tidak mempunyai akses ke portal MyAduan. Hubungi Super Admin jika anda perlukan akses.</p>'
            . '<p><a class="btn" href="' . esc_url(wp_logout_url(wp_login_url(Settings::portal_url()))) . '">Log keluar</a></p>'
            . '</main></body></html>';
    }

    private static function icon_link(): string
    {
        return '<link rel="icon" href="' . esc_url(Settings::icon_url()) . '">';
    }
}
