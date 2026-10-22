<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Public shortcodes. The UI runs in a Shadow DOM so the theme/Elementor CSS cannot break it. */
final class Frontend
{
    public static function register(): void
    {
        add_shortcode('myaduan_borang', [self::class, 'form']);
        add_shortcode('myaduan_semak', [self::class, 'track']);
        add_action('wp_head', [self::class, 'icon']);
    }

    /** Same tab icon as the home page and portal when the site has no WordPress site icon. */
    public static function icon(): void
    {
        if (!has_site_icon()) {
            echo '<link rel="icon" href="' . esc_url(Settings::icon_url()) . '">' . "\n";
        }
    }

    public static function form(): string
    {
        return self::mount('myaduan-borang', [
            'privacy' => Settings::get('privacy_url'),
            'emergency' => Settings::get('emergency_text'),
        ]);
    }

    public static function track(): string
    {
        return self::mount('myaduan-semak', []);
    }

    private static function mount(string $tag, array $data): string
    {
        $url = plugins_url('assets/', MYADUAN_FILE);
        wp_enqueue_script('myaduan-public', $url . 'public.js', [], MYADUAN_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        $attrs = '';
        foreach (['api' => rest_url('myaduan/v1'), 'css' => $url . 'public.css?ver=' . MYADUAN_VERSION, 'logo' => Settings::logo_url(), 'home' => home_url('/'), 'form' => Settings::page_url('form_path'), 'track' => Settings::page_url('track_path')] + $data as $k => $v) {
            $attrs .= ' data-' . $k . '="' . esc_attr($v) . '"';
        }
        // Inline so it beats theme rules that cap content width: the form breaks out of narrow columns but never past the screen.
        $wide = 'display:block;max-width:none;width:min(1120px,calc(100vw - 24px));margin-left:0!important;margin-right:0!important;position:relative;left:50%;transform:translateX(-50%)';
        return "<$tag$attrs style=\"$wide\"></$tag><noscript><p>Sila aktifkan JavaScript untuk menggunakan borang MyAduan.</p></noscript>";
    }
}
