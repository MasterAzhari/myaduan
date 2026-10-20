<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Settings → MyAduan. */
final class Settings
{
    public const OPTION = 'myaduan_settings';
    private const DEFAULTS = [
        'notify_emails' => '',
        'privacy_url' => '',
        'portal_path' => 'adminaduan',
        'emergency_text' => 'Untuk kecemasan yang mengancam nyawa, hubungi 999 (polis/ambulans) atau 994 (bomba) dahulu.',
    ];

    public static function get(string $key): string
    {
        $all = get_option(self::OPTION, []);
        return (string) (is_array($all) && isset($all[$key]) ? $all[$key] : self::DEFAULTS[$key]);
    }

    /** @return list<string> */
    public static function notify_emails(): array
    {
        $list = array_filter(array_map('trim', explode(',', self::get('notify_emails'))), 'is_email');
        if ($list) {
            return array_values($list);
        }
        // Fallback: every Super Admin MyAduan.
        $users = get_users(['capability' => Access::CAP_MANAGE, 'fields' => ['user_email']]);
        $emails = array_values(array_filter(array_map(static fn($u) => $u->user_email, $users), 'is_email'));
        return $emails ?: [get_option('admin_email')];
    }

    public static function portal_url(string $fragment = ''): string
    {
        return home_url('/' . trim(self::get('portal_path'), '/') . '/') . ($fragment ? '#' . $fragment : '');
    }

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page('MyAduan', 'MyAduan', 'manage_options', 'myaduan', [self::class, 'page']);
        });
        add_action('admin_init', static function (): void {
            register_setting('myaduan', self::OPTION, ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitize'], 'default' => self::DEFAULTS]);
        });
    }

    public static function sanitize(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $emails = array_filter(array_map('sanitize_email', explode(',', (string) ($in['notify_emails'] ?? ''))), 'is_email');
        return [
            'notify_emails' => implode(', ', $emails),
            'privacy_url' => esc_url_raw((string) ($in['privacy_url'] ?? '')),
            'portal_path' => sanitize_title((string) ($in['portal_path'] ?? self::DEFAULTS['portal_path'])) ?: self::DEFAULTS['portal_path'],
            'emergency_text' => sanitize_text_field((string) ($in['emergency_text'] ?? self::DEFAULTS['emergency_text'])),
        ];
    }

    public static function page(): void
    {
        $name = self::OPTION;
        echo '<div class="wrap"><h1>MyAduan</h1><form method="post" action="options.php">';
        settings_fields('myaduan');
        echo '<table class="form-table" role="presentation">';
        $row = static function (string $key, string $label, string $help, string $type = 'text') use ($name): void {
            printf(
                '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%5$s" class="regular-text" id="%1$s" name="%3$s[%1$s]" value="%4$s"><p class="description">%6$s</p></td></tr>',
                esc_attr($key), esc_html($label), esc_attr($name), esc_attr(self::get($key)), esc_attr($type), esc_html($help)
            );
        };
        $row('notify_emails', 'E-mel notifikasi aduan baru', 'Pisahkan dengan koma. Kosong = semua Super Admin MyAduan.');
        $row('privacy_url', 'URL notis privasi', 'Dipaparkan pada borang aduan.', 'url');
        $row('portal_path', 'Laluan portal admin', 'Contoh: adminaduan → ' . home_url('/adminaduan/'));
        $row('emergency_text', 'Mesej kecemasan', 'Dipaparkan di atas borang.');
        echo '</table>';
        submit_button();
        echo '</form><h2>Shortcode</h2><p><code>[myaduan_borang]</code> borang aduan · <code>[myaduan_semak]</code> semak status</p>';
        printf('<p>Folder simpanan: <code>%s</code></p></div>', esc_html(Storage::root()));
    }
}
