<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-off import of complaints from the old Google Sheet ("ADUAN" tab saved as CSV).
 *
 * Photos linked in Gambar_Bukti_URL are downloaded from Google Drive and stored like
 * new uploads (resized, metadata removed, private folder). That needs the Drive files
 * to be shared as "anyone with the link"; photos that cannot be fetched stay as links
 * in the import note, and running the import again retries them.
 *
 * Safe to run again: a reference that already exists is skipped. Personal data is
 * encrypted the same way as new complaints. The complainant's home address and
 * postcode are not kept (the new system does not collect them).
 */
final class Import
{
    /** Old Sheet statuses → MyAduan statuses (same mapping the old Apps Script used). */
    private const STATUS = [
        'BARU' => Workflow::RECEIVED,
        'MENUNGGU MAKLUM BALAS' => Workflow::IN_ACTION,
        'SELESAI' => Workflow::AWAITING_CLOSE,
    ];

    public static function register(): void
    {
        add_action('admin_post_myaduan_import', [self::class, 'handle_upload']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('myaduan import-sheet', static function (array $args): void {
                $report = self::csv((string) ($args[0] ?? ''), get_current_user_id());
                \WP_CLI::success(self::summary($report));
                foreach ($report['errors'] as $line) {
                    \WP_CLI::warning($line);
                }
            });
        }
    }

    /** Settings → MyAduan upload form posts here. */
    public static function handle_upload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Tiada kebenaran.', 403);
        }
        check_admin_referer('myaduan_import');
        $file = $_FILES['sheet_csv'] ?? null;
        $report = ['imported' => 0, 'skipped' => 0, 'errors' => ['Tiada fail CSV dipilih.']];
        if (is_array($file) && ($file['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $report = self::csv($file['tmp_name'], get_current_user_id());
        }
        set_transient('myaduan_import_report_' . get_current_user_id(), $report, 600);
        wp_safe_redirect(admin_url('options-general.php?page=myaduan#import'));
        exit;
    }

    public static function summary(array $r): string
    {
        $text = sprintf('%d aduan diimport, %d dilangkau (sudah ada), %d gambar disimpan', $r['imported'], $r['skipped'], $r['photos'] ?? 0);
        if (!empty($r['photos_failed'])) {
            $text .= sprintf(', %d gambar tidak dapat dimuat turun (pastikan folder Google Drive dikongsi "Anyone with the link", kemudian import sekali lagi)', $r['photos_failed']);
        }
        return $text . sprintf(', %d baris bermasalah.', count($r['errors']));
    }

    /** @return array{imported:int, skipped:int, errors:list<string>} */
    public static function csv(string $path, int $actor_id): array
    {
        $report = ['imported' => 0, 'skipped' => 0, 'photos' => 0, 'photos_failed' => 0, 'errors' => []];
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // Photo downloads can take a while.
        }
        $fh = $path !== '' && is_readable($path) ? fopen($path, 'r') : false;
        if (!$fh) {
            $report['errors'][] = 'Fail CSV tidak dapat dibaca.';
            return $report;
        }
        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) {
            $report['errors'][] = 'Fail CSV kosong.';
            return $report;
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // Excel/Sheets BOM
        $header = array_map('trim', $header);
        if (!in_array('No_Rujukan', $header, true) || !in_array('Butiran_Aduan', $header, true)) {
            $report['errors'][] = 'Bukan fail tab ADUAN: lajur No_Rujukan dan Butiran_Aduan tidak ditemui.';
            return $report;
        }
        $line = 1;
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || !array_filter($cells, static fn($c) => trim((string) $c) !== '')) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($cells[$i] ?? ''));
            }
            try {
                self::row($row, $actor_id, $report) ? $report['imported']++ : $report['skipped']++;
            } catch (\Throwable $e) {
                $report['errors'][] = "Baris $line ({$row['No_Rujukan']}): " . $e->getMessage();
            }
        }
        fclose($fh);
        return $report;
    }

    /** Imports one Sheet row. Returns false when the reference already exists (its missing photos are retried). */
    private static function row(array $v, int $actor_id, array &$report): bool
    {
        global $wpdb;
        $cases = Schema::table('cases');
        $reference = strtoupper($v['No_Rujukan'] ?? '');
        if (!preg_match('/^GOM-\d{8}-[A-F0-9]{10}$/D', $reference)) {
            throw new \RuntimeException('nombor rujukan tidak sah');
        }
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $cases WHERE reference = %s", $reference));
        if ($existing) {
            $has = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('attachments') . ' WHERE case_id = %d', $existing));
            if (!$has) {
                self::save_photos($existing, $reference, self::photos($v, $report), $actor_id, $report);
            }
            return false;
        }
        $images = self::photos($v, $report);
        $details = trim(str_replace("\r\n", "\n", $v['Butiran_Aduan'] ?? ''));
        if ($details === '') {
            throw new \RuntimeException('butiran aduan kosong');
        }
        $crypto = Plugin::crypto();
        $catalog = new Catalog();
        $ic_raw = $v['No_KP'] ?? '';
        $ic = Validator::normalize_ic($ic_raw) ?? preg_replace('/\D/', '', $ic_raw);
        $phone = Validator::normalize_phone($v['No_Telefon'] ?? '') ?? preg_replace('/\D/', '', $v['No_Telefon'] ?? '');
        $email = strtolower($v['Email'] ?? '');
        $created = self::utc($v['Tarikh_Masa'] ?? '') ?? Cases::now();
        $updated = self::utc($v['Tarikh_Kemas_Kini'] ?? '') ?? $created;
        $old_status = strtoupper($v['Status'] ?? '');
        $status = self::STATUS[$old_status] ?? (in_array($old_status, Workflow::STATUSES, true) ? $old_status : Workflow::RECEIVED);
        $code = strtoupper(substr($v['Kod_Kategori'] ?? '', 0, 3));
        $priority = strtoupper($v['Tahap_Keutamaan'] ?? '');
        [$lat, $lng] = self::coords($v['Koordinat'] ?? '');
        $title = $v['Tajuk_Aduan'] ?? '';

        $wpdb->query('START TRANSACTION');
        try {
            $ok = $wpdb->insert($cases, [
                'reference' => $reference,
                'version' => 1,
                'status' => $status,
                'priority' => in_array($priority, Workflow::PRIORITIES, true) ? $priority : 'BIASA',
                'title' => mb_substr($title !== '' ? $title : Validator::provisional_title($details), 0, 150),
                'details' => mb_substr($details, 0, 10000),
                'location_text' => mb_substr($v['Lokasi_Kejadian'] ?? '', 0, 500),
                'lat' => $lat,
                'lng' => $lng,
                'category_code' => $catalog->has_category($code) ? $code : null,
                'dun_slug' => self::dun($catalog, $v['DUN'] ?? ''),
                'name_enc' => $crypto->encrypt($v['Nama_Penuh'] ?? ''),
                'ic_enc' => $crypto->encrypt($ic),
                'ic_index' => $crypto->blind_index($ic),
                'phone_enc' => $crypto->encrypt($phone),
                'email_enc' => is_email($email) ? $crypto->encrypt($email) : null,
                'source' => 'sheet',
                'created_at' => $created,
                'updated_at' => $updated,
                'closed_at' => $status === Workflow::CLOSED ? $updated : null,
            ]);
            if ($ok === false) {
                throw new \RuntimeException('gagal simpan: ' . $wpdb->last_error);
            }
            $id = (int) $wpdb->insert_id;
            $wpdb->insert(Schema::table('events'), [
                'case_id' => $id,
                'actor_id' => $actor_id,
                'event' => 'IMPORT',
                'status_from' => null,
                'status_to' => $status,
                'note' => self::note($v),
                'changes' => null,
                'request_id' => null,
                'created_at' => Cases::now(),
            ]);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        self::save_photos($id, $reference, $images, $actor_id, $report);
        return true;
    }

    /** Downloads the row's Drive photos. Failures are counted, not fatal. */
    private static function photos(array $v, array &$report): array
    {
        $images = [];
        foreach (self::drive_ids($v['Gambar_Bukti_URL'] ?? '') as $n => $file_id) {
            if (count($images) >= Files::MAX_PUBLIC) {
                break;
            }
            $res = wp_remote_get('https://drive.google.com/uc?export=download&id=' . rawurlencode($file_id), ['timeout' => 20, 'redirection' => 5, 'limit_response_size' => 6 * 1024 * 1024]);
            $bytes = is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200 ? '' : (string) wp_remote_retrieve_body($res);
            try {
                $images[] = ImageProcessor::process($bytes) + ['name' => 'gambar-' . ($n + 1)];
            } catch (WorkflowException) {
                $report['photos_failed']++; // Private Drive file (login page), deleted, or not an image.
            }
        }
        return $images;
    }

    private static function save_photos(int $case_id, string $reference, array $images, int $actor_id, array &$report): void
    {
        global $wpdb;
        if (!$images) {
            return;
        }
        $written = [];
        try {
            foreach (Files::write($reference, $images, 'public', $actor_id, Cases::now(), $written) as $att) {
                if ($wpdb->insert(Schema::table('attachments'), ['case_id' => $case_id] + $att) === false) {
                    throw new \RuntimeException('insert_attachment');
                }
                $report['photos']++;
            }
        } catch (\RuntimeException $e) {
            Files::delete_paths($written);
            $report['photos_failed'] += count($images);
        }
    }

    /** @return list<string> Drive file ids from "https://drive.google.com/file/d/ID/view" or "...open?id=ID" links. */
    public static function drive_ids(string $urls): array
    {
        preg_match_all('#https://drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:export=\w+&)?id=)([\w-]{10,})#', $urls, $m);
        return array_values(array_unique($m[1]));
    }

    /** What the old Sheet knew that has no field of its own here, kept for the officers. */
    private static function note(array $v): string
    {
        $lines = ['Diimport dari Google Sheet lama.'];
        foreach (['Status' => 'Status asal', 'Pegawai' => 'Pegawai asal', 'Kategori_Aduan' => 'Kategori asal', 'DUN' => 'DUN asal', 'Tarikh_Kejadian' => 'Tarikh kejadian', 'Catatan' => 'Catatan'] as $key => $label) {
            if (($v[$key] ?? '') !== '') {
                $lines[] = "$label: {$v[$key]}";
            }
        }
        $photos = array_filter(preg_split('/\s+/', $v['Gambar_Bukti_URL'] ?? ''), static fn($u) => str_starts_with($u, 'https://'));
        if ($photos) {
            $lines[] = 'Gambar (Google Drive): ' . implode(' ', $photos);
        }
        return implode("\n", $lines);
    }

    /** Sheet times are Malaysia time, "2026-10-01 14:30:00" or "01/10/2026 14:30:00". */
    private static function utc(string $value): ?string
    {
        $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!d/m/Y H:i:s', '!d/m/Y H:i', '!Y-m-d', '!d/m/Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $value, $tz);
            if ($d && \DateTimeImmutable::getLastErrors() === false) {
                return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private static function coords(string $value): array
    {
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
            if ($lat > 0.5 && $lat < 7.5 && $lng > 99.5 && $lng < 119.5) {
                return [$lat, $lng];
            }
        }
        return [null, null];
    }

    private static function dun(Catalog $catalog, string $name): ?string
    {
        $want = sanitize_title($name);
        foreach ($catalog->areas() as $d) {
            if ($want !== '' && ($d['slug'] === $want || sanitize_title($d['name']) === $want)) {
                return $d['slug'];
            }
        }
        return null;
    }
}
