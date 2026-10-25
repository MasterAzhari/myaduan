<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Settings → MyAduan. */
final class Settings
{
    public const OPTION = 'myaduan_settings';
    /** Every admin, Super Admin and system sender address uses this domain. */
    public const STAFF_DOMAIN = 'myaduan.my';
    private const DEFAULTS = [
        'notify_emails' => '',
        'mail_from' => 'info@myaduan.my',
        'mail_from_name' => 'MyAduan Parlimen Gombak',
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

    public static function staff_domain(): string
    {
        return strtolower((string) apply_filters('myaduan_staff_domain', self::STAFF_DOMAIN));
    }

    /** Whether an address is on the MyAduan staff domain (e.g. nama@myaduan.my). */
    public static function is_staff_email(string $email): bool
    {
        return (bool) is_email($email) && str_ends_with(strtolower($email), '@' . self::staff_domain());
    }

    /** @return list<string> */
    public static function notify_emails(): array
    {
        $list = array_filter(array_map('trim', explode(',', self::get('notify_emails'))), [self::class, 'is_staff_email']);
        if ($list) {
            return array_values($list);
        }
        // Fallback: every Super Admin MyAduan with a staff address, then the sender mailbox.
        $users = get_users(['capability' => Access::CAP_MANAGE, 'fields' => ['user_email']]);
        $emails = array_values(array_filter(array_map(static fn($u) => $u->user_email, $users), [self::class, 'is_staff_email']));
        return $emails ?: [self::mail_from()];
    }

    /** Sender of every MyAduan e-mail; always on the staff domain. */
    public static function mail_from(): string
    {
        $from = self::get('mail_from');
        return self::is_staff_email($from) ? $from : 'info@' . self::staff_domain();
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
        $staff = array_filter($emails, [self::class, 'is_staff_email']);
        $domain = self::staff_domain();
        if (count($staff) < count($emails) && function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'notify_domain', "E-mel notifikasi mesti @$domain. Alamat lain telah dibuang: " . implode(', ', array_diff($emails, $staff)));
        }
        $from = sanitize_email((string) ($in['mail_from'] ?? ''));
        if ($from !== '' && !self::is_staff_email($from) && function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'from_domain', "E-mel penghantar mesti @$domain. Ditetapkan semula kepada info@$domain.");
        }
        return [
            'notify_emails' => implode(', ', $staff),
            'mail_from' => self::is_staff_email($from) ? $from : 'info@' . $domain,
            'mail_from_name' => sanitize_text_field((string) ($in['mail_from_name'] ?? '')) ?: self::DEFAULTS['mail_from_name'],
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
        $row('notify_emails', 'E-mel notifikasi aduan baru', 'Mesti @' . self::staff_domain() . '. Pisahkan dengan koma. Kosong = semua Super Admin MyAduan.');
        $row('mail_from', 'E-mel penghantar', 'Semua e-mel MyAduan (termasuk pengesahan kepada pengadu) dihantar dari alamat ini. Mesti @' . self::staff_domain() . ' dan wujud sebagai mailbox di Hostinger.', 'email');
        $row('mail_from_name', 'Nama penghantar', 'Nama yang dilihat penerima.');
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
        printf('<p>Folder simpanan: <code>%s</code></p>', esc_html(Storage::root()));

        echo '<h2 id="import">Import aduan dari Google Sheet lama</h2>';
        $report = get_transient('myaduan_import_report_' . get_current_user_id());
        if (is_array($report)) {
            delete_transient('myaduan_import_report_' . get_current_user_id());
            printf('<div class="notice notice-%s inline"><p>%s</p>', $report['errors'] ? 'warning' : 'success', esc_html(Import::summary($report)));
            foreach (array_slice($report['errors'], 0, 20) as $err) {
                echo '<br>' . esc_html($err);
            }
            echo '</p></div>';
        }
        echo '<p>Dalam Google Sheet, buka tab <strong>ADUAN</strong> → File → Download → <strong>Comma-separated values (.csv)</strong>, kemudian upload di sini. Aduan yang sudah ada (nombor rujukan sama) dilangkau, jadi selamat diimport semula. Gambar dimuat turun dari Google Drive jika folder gambar dikongsi "Anyone with the link".</p>';
        printf('<form method="post" action="%s" enctype="multipart/form-data">', esc_url(admin_url('admin-post.php')));
        echo '<input type="hidden" name="action" value="myaduan_import">';
        wp_nonce_field('myaduan_import');
        echo '<input type="file" name="sheet_csv" accept=".csv,text/csv" required> ';
        submit_button('Import', 'secondary', 'submit', false);
        echo '</form></div>';
    }
}
