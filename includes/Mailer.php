<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email notifications through a queue, sent by WP-Cron (driven by a real
 * hPanel cron job on Hostinger). Emails never contain the complainant's
 * personal data, only the reference and a link into the portal.
 */
final class Mailer
{
    public const HOOK = 'myaduan_send_mail';
    public const BATCH = 20;
    public const MAX_ATTEMPTS = 3;

    public static function register(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s['myaduan_5min'] = ['interval' => 300, 'display' => 'MyAduan: setiap 5 minit'];
            return $s;
        });
        add_action(self::HOOK, [self::class, 'process']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 60, 'myaduan_5min', self::HOOK);
            }
        });
        add_action('myaduan_case_created', [self::class, 'on_created'], 10, 2);
        add_action('myaduan_case_operated', [self::class, 'on_operated'], 10, 6);
    }

    public static function queue(string $dedupe_key, string $to, string $subject, string $body, ?int $case_id = null): void
    {
        global $wpdb;
        if (!is_email($to)) {
            return;
        }
        $now = Cases::now();
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Schema::table('mail_queue') . ' (dedupe_key, case_id, recipient, subject, body, state, attempts, created_at, updated_at) VALUES (%s, %d, %s, %s, %s, %s, 0, %s, %s)',
            mb_substr($dedupe_key, 0, 120), (int) $case_id, $to, $subject, $body, 'pending', $now, $now
        ));
    }

    public static function on_created(int $id, string $reference): void
    {
        $case = self::summary($id);
        foreach (Settings::notify_emails() as $to) {
            self::queue("new:$id:$to", $to, "[MyAduan] Aduan baru $reference", self::body([
                'Aduan baru diterima dan menunggu saringan.',
                'Rujukan: ' . $reference,
                'Tajuk: ' . $case['title'],
                'Lokasi: ' . $case['location_text'],
            ], $id), $id);
        }
    }

    public static function on_operated(int $id, string $op, array $actor, string $from, string $to, array $changes): void
    {
        $case = self::summary($id);
        $ref = $case['reference'];
        if (isset($changes['owner_id'])) {
            $owner = get_userdata((int) $changes['owner_id'][1]);
            if ($owner && $owner->ID !== $actor['id']) {
                self::queue("assign:$id:{$owner->ID}:" . md5((string) $case['assigned_at']), $owner->user_email, "[MyAduan] Kes $ref ditugaskan kepada anda", self::body([
                    'Kes berikut ditugaskan kepada anda. Sila log masuk untuk terima tugasan.',
                    'Rujukan: ' . $ref,
                    'Tajuk: ' . $case['title'],
                    'Keutamaan: ' . $case['priority'],
                ], $id), $id);
            }
        }
        if ($op === 'request_close') {
            foreach (Settings::notify_emails() as $email) {
                self::queue("close:$id:{$case['version']}:$email", $email, "[MyAduan] Kes $ref menunggu pengesahan penutupan", self::body([
                    $actor['name'] . ' memohon kes ini ditutup.',
                    'Rujukan: ' . $ref,
                    'Tajuk: ' . $case['title'],
                ], $id), $id);
            }
        }
        if ($op === 'return' && (int) $case['owner_id']) {
            $owner = get_userdata((int) $case['owner_id']);
            if ($owner) {
                self::queue("return:$id:{$case['version']}", $owner->user_email, "[MyAduan] Kes $ref dikembalikan untuk tindakan", self::body([
                    'Super Admin mengembalikan kes ini untuk tindakan lanjut. Lihat catatan dalam portal.',
                    'Rujukan: ' . $ref,
                ], $id), $id);
            }
        }
    }

    /** Sends a batch. Safe to run concurrently: rows are claimed before sending. */
    public static function process(): int
    {
        global $wpdb;
        $table = Schema::table('mail_queue');
        // Rows stuck in "sending" for 15 minutes are treated as failed attempts.
        $wpdb->query($wpdb->prepare("UPDATE $table SET state = 'pending', last_error = 'timeout' WHERE state = 'sending' AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 900)));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE state = 'pending' AND attempts < %d ORDER BY id LIMIT %d", self::MAX_ATTEMPTS, self::BATCH));
        $sent = 0;
        foreach ($ids as $id) {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET state = 'sending', attempts = attempts + 1, updated_at = %s WHERE id = %d AND state = 'pending'", Cases::now(), $id));
            if ($claimed !== 1) {
                continue;
            }
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
            $error = '';
            $catch = static function (\WP_Error $e) use (&$error): void {
                $error = $e->get_error_message();
            };
            add_action('wp_mail_failed', $catch);
            $ok = wp_mail($m['recipient'], $m['subject'], $m['body']);
            remove_action('wp_mail_failed', $catch);
            $state = $ok ? 'sent' : ((int) $m['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending');
            $wpdb->update($table, ['state' => $state, 'last_error' => $ok ? null : mb_substr($error ?: 'wp_mail gagal', 0, 255), 'updated_at' => Cases::now()], ['id' => $id]);
            $sent += $ok ? 1 : 0;
        }
        return $sent;
    }

    private static function summary(int $id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id, reference, title, location_text, priority, owner_id, assigned_at, version FROM ' . Schema::table('cases') . ' WHERE id = %d', $id), ARRAY_A) ?: [];
    }

    private static function body(array $lines, int $id): string
    {
        $lines[] = '';
        $lines[] = 'Buka kes: ' . Settings::portal_url('/kes/' . $id);
        $lines[] = '';
        $lines[] = '— MyAduan Parlimen Gombak (e-mel automatik, jangan balas)';
        return implode("\n", $lines);
    }
}
