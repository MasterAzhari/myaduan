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
        'home_enabled' => '',
        'form_path' => 'aduan',
        'track_path' => 'semak',
        'home_logo_url' => '',
        'home_image_url' => '',
        'home_description' => 'Laporkan masalah setempat di Parlimen Gombak dalam talian. Dapat nombor rujukan serta-merta dan semak status aduan anda sehingga selesai.',
        'home_share_image_url' => '',
        'home_mp_name' => "YAB Dato' Seri Amirudin Shari",
        'home_mp_title' => 'Menteri Besar Selangor merangkap Ahli Parlimen Gombak',
        'home_mp_photo_url' => '',
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

    /** The one MyAduan logo used on every page: the uploaded official logo, or the bundled one. */
    public static function logo_url(): string
    {
        return self::get('home_logo_url') ?: plugins_url('assets/img/logo-myaduan.png', MYADUAN_FILE) . '?ver=' . rawurlencode(MYADUAN_VERSION);
    }

    /** Browser tab icon: the WordPress site icon when set, otherwise the small MyAduan logo. */
    public static function icon_url(): string
    {
        return get_site_icon_url(64) ?: plugins_url('assets/img/logo-myaduan-64.png', MYADUAN_FILE) . '?ver=' . rawurlencode(MYADUAN_VERSION);
    }

    /** URL of a public page whose path is kept in settings (form_path, track_path). */
    public static function page_url(string $key): string
    {
        return home_url('/' . trim(self::get($key), '/') . '/');
    }

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page('MyAduan', 'MyAduan', 'manage_options', 'myaduan', [self::class, 'page']);
        });
        // The landing page and public pages depend on these settings, so drop LiteSpeed's page cache when they change.
        add_action('update_option_' . self::OPTION, [self::class, 'purge_cache']);
        add_action('add_option_' . self::OPTION, [self::class, 'purge_cache']);
        add_action('admin_init', static function (): void {
            register_setting('myaduan', self::OPTION, ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitize'], 'default' => self::DEFAULTS]);
        });
    }

    public static function purge_cache(): void
    {
        do_action('litespeed_purge_all');
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
            'home_enabled' => empty($in['home_enabled']) ? '' : '1',
            'form_path' => sanitize_title((string) ($in['form_path'] ?? self::DEFAULTS['form_path'])) ?: self::DEFAULTS['form_path'],
            'track_path' => sanitize_title((string) ($in['track_path'] ?? self::DEFAULTS['track_path'])) ?: self::DEFAULTS['track_path'],
            'home_logo_url' => esc_url_raw((string) ($in['home_logo_url'] ?? '')),
            'home_image_url' => esc_url_raw((string) ($in['home_image_url'] ?? '')),
            'home_description' => sanitize_text_field((string) ($in['home_description'] ?? '')) ?: self::DEFAULTS['home_description'],
            'home_share_image_url' => esc_url_raw((string) ($in['home_share_image_url'] ?? '')),
            'home_mp_name' => sanitize_text_field((string) ($in['home_mp_name'] ?? '')),
            'home_mp_title' => sanitize_text_field((string) ($in['home_mp_title'] ?? '')),
            'home_mp_photo_url' => esc_url_raw((string) ($in['home_mp_photo_url'] ?? '')),
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
        $row('emergency_text', 'Mesej kecemasan', 'Dipaparkan di atas borang dan di halaman utama.');
        printf(
            '<tr><th scope="row">Halaman utama MyAduan</th><td><label><input type="checkbox" name="%1$s[home_enabled]" value="1"%2$s> Paparkan halaman utama MyAduan bila orang buka %3$s</label><p class="description">Menggantikan halaman utama tema. Halaman lain tidak terjejas.</p></td></tr>',
            esc_attr($name), checked(self::get('home_enabled'), '1', false), esc_html(home_url('/'))
        );
        echo '<tr><th scope="row">Status halaman utama</th><td><ul style="margin:0">';
        foreach (Home::diagnose() as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul></td></tr>';
        $row('form_path', 'Laluan borang aduan', 'Page yang ada [myaduan_borang]. Contoh: aduan → ' . home_url('/aduan/'));
        $row('track_path', 'Laluan semak status', 'Page yang ada [myaduan_semak]. Contoh: semak → ' . home_url('/semak/'));
        $row('home_logo_url', 'URL logo', 'Pilihan. Dipakai di halaman utama, borang aduan, semak status dan portal admin. Upload logo rasmi di Media → Add New, salin URL fail ke sini. Kosong = logo MyAduan biasa.', 'url');
        $row('home_image_url', 'URL gambar utama (halaman utama)', 'Pilihan. Contoh: gambar Ahli Parlimen atau kawasan Gombak (nisbah 4:3 atau segi empat). Kosong = ilustrasi MyAduan.', 'url');
        $row('home_description', 'Meta description (halaman utama)', 'Ayat ringkas untuk Google dan preview link. Elok bawah 160 aksara.');
        $row('home_share_image_url', 'URL gambar preview (feature image)', 'Pilihan. Gambar bila link myaduan.my dikongsi di WhatsApp/Facebook, saiz 1200×630. Kosong = gambar MyAduan biasa.', 'url');
        $row('home_mp_name', 'Nama Ahli Parlimen', 'Dipaparkan dalam bahagian "Inisiatif" di halaman utama. Kosong = bahagian itu disembunyikan.');
        $row('home_mp_title', 'Jawatan Ahli Parlimen', 'Contoh: Menteri Besar Selangor merangkap Ahli Parlimen Gombak');
        $row('home_mp_photo_url', 'URL gambar Ahli Parlimen', 'Pilihan, untuk tukar gambar (potret, nisbah 4:5). Upload di Media, salin URL ke sini. Kosong = gambar YAB yang disertakan dalam plugin.', 'url');
        echo '</table>';
        submit_button();
        echo '</form><h2>Shortcode</h2><p><code>[myaduan_borang]</code> borang aduan · <code>[myaduan_semak]</code> semak status</p>';
        printf('<p>Folder simpanan: <code>%s</code></p></div>', esc_html(Storage::root()));
    }
}
