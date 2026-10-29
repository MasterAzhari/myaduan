<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email through a queue, sent by WP-Cron (driven by a real hPanel cron job on
 * Hostinger). Staff notifications live in the portal (Notifications); copies by
 * email go out only when Settings → "E-mel notifikasi staf" is ticked, and never
 * contain the complainant's personal data. The one email to the complainant (if
 * they gave an address) carries their reference number; its address is wiped
 * from the queue once sent. Referral emails to agencies carry the complaint and
 * its photos but no complainant details.
 *
 * Mail goes out right after the request that queued it (cron picks up the rest).
 * Each row keeps its state for the portal: pending → sent, failed, or bounced
 * once Mailbox finds the returned message in the sender mailbox.
 */
final class Mailer
{
    public const HOOK = 'myaduan_send_mail';
    public const BATCH = 20;
    public const MAX_ATTEMPTS = 3;
    /** Dedupe-key prefix of the complainant's acknowledgement. */
    private const ACK = 'ack:';
    /** Header that ties a returned (bounced) message back to its queue row. */
    public const ID_HEADER = 'X-MyAduan-Id';
    /** Kept in the queued headers only: the sender name for this message, never sent as a header. */
    private const FROM_NAME_HEADER = 'X-MyAduan-From-Name';
    public const STATES = [
        'pending' => 'Belum dihantar',
        'sending' => 'Sedang dihantar',
        'sent' => 'Dihantar',
        'failed' => 'Gagal dihantar',
        'bounced' => 'Bounce (ditolak)',
    ];
    private static bool $flush = false;

    public static function register(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s['myaduan_5min'] = ['interval' => 300, 'display' => 'MyAduan: setiap 5 minit'];
            return $s;
        });
        add_action(self::HOOK, [self::class, 'process']);
        add_action(self::HOOK, [Mailbox::class, 'check'], 20);
        add_action('shutdown', [self::class, 'flush'], 99);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 60, 'myaduan_5min', self::HOOK);
            }
        });
        add_action('myaduan_case_created', [self::class, 'on_created'], 10, 2);
        add_action('myaduan_case_operated', [self::class, 'on_operated'], 10, 6);
    }

    /**
     * @param list<string> $headers extra headers such as "Cc: a@b.my"
     * @param list<int> $attachments attachment ids, resolved to files when sending
     */
    public static function queue(string $dedupe_key, string $to, string $subject, string $body, ?int $case_id = null, array $headers = [], array $attachments = [], ?int $referral_id = null): void
    {
        global $wpdb;
        if (!is_email($to)) {
            return;
        }
        $now = Cases::now();
        $added = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Schema::table('mail_queue') . ' (dedupe_key, case_id, referral_id, recipient, subject, body, headers, attachments, state, attempts, created_at, updated_at) VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %s, 0, %s, %s)',
            mb_substr($dedupe_key, 0, 120), (int) $case_id, (int) $referral_id, $to, $subject, $body,
            $headers ? wp_json_encode(array_values($headers)) : '', $attachments ? wp_json_encode(array_values($attachments)) : '',
            'pending', $now, $now
        ));
        self::$flush = self::$flush || $added === 1;
    }

    /** Sends what this request queued once the response has gone back to the browser. */
    public static function flush(): void
    {
        if (!self::$flush) {
            return;
        }
        self::$flush = false;
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        self::process();
    }

    /** @return array{state:string, label:string, error:?string, at:?string} what the portal shows for one message */
    public static function status(array $row): array
    {
        $state = (string) $row['state'];
        $label = self::STATES[$state] ?? $state;
        if ($state === 'pending' && (int) $row['attempts'] > 0) {
            $label = 'Belum dihantar (cuba semula)';
        }
        return [
            'state' => $state,
            'label' => $label,
            'error' => $row['last_error'] ?: null,
            'at' => Cases::iso($row['sent_at'] ?: $row['updated_at']),
        ];
    }

    /** @return list<array> recent messages for the Super Admin's e-mail log; complainants' addresses are never shown */
    public static function log(int $limit = 100, ?string $state = null): array
    {
        global $wpdb;
        $q = Schema::table('mail_queue');
        $where = $state !== null && isset(self::STATES[$state]) ? $wpdb->prepare('WHERE m.state = %s', $state) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.dedupe_key, m.case_id, m.referral_id, m.recipient, m.subject, m.state, m.attempts, m.last_error, m.sent_at, m.created_at, m.updated_at, c.reference
             FROM $q m LEFT JOIN " . Schema::table('cases') . " c ON c.id = m.case_id $where ORDER BY m.id DESC LIMIT %d",
            max(1, min(500, $limit))
        ), ARRAY_A);
        return array_map(static function (array $r): array {
            $ack = str_starts_with((string) $r['dedupe_key'], self::ACK);
            if ($ack && $r['last_error']) {
                // Mail server errors and bounce reasons often quote the recipient: the complainant's address.
                $r['last_error'] = preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[e-mel pengadu]', (string) $r['last_error']);
            }
            return self::log_row($r, $ack);
        }, $rows);
    }

    private static function log_row(array $r, bool $ack): array
    {
        return [
            'id' => (int) $r['id'],
            'kind' => self::kind((string) $r['dedupe_key']),
            'recipient' => $ack ? 'Pengadu' : $r['recipient'],
            'subject' => $r['subject'],
            'case_id' => $r['case_id'] ? (int) $r['case_id'] : null,
            'reference' => $r['reference'],
            'attempts' => (int) $r['attempts'],
            'created_at' => Cases::iso($r['created_at']),
            'status' => self::status($r),
            'can_retry' => in_array($r['state'], ['failed', 'bounced'], true) && !$ack,
        ];
    }

    /** @return array<string,int> number of messages in each state */
    public static function counts(): array
    {
        global $wpdb;
        $out = array_fill_keys(array_keys(self::STATES), 0);
        foreach ($wpdb->get_results('SELECT state, COUNT(*) n FROM ' . Schema::table('mail_queue') . ' GROUP BY state', ARRAY_A) as $r) {
            $out[$r['state']] = (int) $r['n'];
        }
        return $out;
    }

    /** Queues and sends a short test message now; returns its status. */
    public static function test(string $to, string $by): array
    {
        global $wpdb;
        $to = sanitize_email($to);
        if (!is_email($to)) {
            throw new Rest\FieldErrors(['to' => 'Masukkan alamat e-mel yang sah.']);
        }
        $key = 'test:' . wp_generate_uuid4();
        self::queue($key, $to, 'E-mel ujian MyAduan', implode("\n", [
            'Ini e-mel ujian dari sistem MyAduan Parlimen Gombak.',
            '',
            'Dihantar oleh: ' . $by,
            'Masa: ' . wp_date('d/m/Y g:i A'),
            '',
            'Jika anda menerima e-mel ini, penghantaran e-mel MyAduan berfungsi.',
        ]));
        self::$flush = false;
        self::process();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('mail_queue') . ' WHERE dedupe_key = %s', $key), ARRAY_A);
        return $row ? ['id' => (int) $row['id'], 'status' => self::status($row)] : [];
    }

    /** Puts a failed or bounced message back in the queue, for after the address or mailbox is fixed. */
    public static function retry(int $id): void
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT dedupe_key, state FROM ' . Schema::table('mail_queue') . ' WHERE id = %d', $id), ARRAY_A);
        if (!$row) {
            throw new WorkflowException('E-mel tidak ditemui.', 'not_found', 404);
        }
        if (!in_array($row['state'], ['failed', 'bounced'], true) || str_starts_with($row['dedupe_key'], self::ACK)) {
            throw new WorkflowException('Hanya e-mel yang gagal atau bounce boleh dihantar semula.');
        }
        $wpdb->update(Schema::table('mail_queue'), ['state' => 'pending', 'attempts' => 0, 'last_error' => null, 'sent_at' => null, 'updated_at' => Cases::now()], ['id' => $id]);
        self::$flush = true;
    }

    private static function kind(string $key): string
    {
        return match (strstr($key, ':', true)) {
            'ref' => 'Agensi',
            'ack' => 'Pengadu',
            'test' => 'Ujian',
            default => 'Staf',
        };
    }

    /** Staff copies by email are optional; the portal is where staff are notified. */
    private static function staff_email_on(): bool
    {
        return Settings::get('staff_email') === '1';
    }

    /** The referral to one agency: complaint, location and photos, no complainant details. */
    public static function queue_referral(int $case_id, array $agency, string $message, string $request_id, string $sender_name = ''): void
    {
        global $wpdb;
        $case = $wpdb->get_row($wpdb->prepare(
            'SELECT reference, title, details, location_text, lat, lng, category_code, dun_slug, created_at FROM ' . Schema::table('cases') . ' WHERE id = %d',
            $case_id
        ), ARRAY_A);
        if (!$case) {
            return;
        }
        $catalog = new Catalog();
        $category = '';
        foreach ($catalog->categories() as $c) {
            if ($c['code'] === $case['category_code']) {
                $category = $c['name'] . ' (' . $c['code'] . ')';
            }
        }
        $dun = '';
        foreach ($catalog->areas() as $d) {
            if ($d['slug'] === $case['dun_slug']) {
                $dun = $d['name'];
            }
        }
        $photos = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Schema::table('attachments') . " WHERE case_id = %d AND kind = 'public' ORDER BY id LIMIT 5",
            $case_id
        )));
        $created = (new \DateTimeImmutable($case['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Kuala_Lumpur'));
        $ref = $case['reference'];
        $lines = [
            'Kepada: ' . ($agency['officer'] !== '' ? $agency['officer'] . ', ' : '') . $agency['name'],
            '',
            'Tuan/Puan,',
            '',
            "RUJUKAN ADUAN AWAM $ref: " . mb_strtoupper($case['title']),
            '',
            'Pejabat Parlimen Gombak menerima aduan awam berikut melalui sistem MyAduan dan memanjangkannya kepada pihak tuan/puan untuk tindakan selanjutnya.',
            '',
            $message,
            '',
            'BUTIRAN ADUAN',
            'No. rujukan: ' . $ref,
            'Tarikh aduan: ' . $created->format('d/m/Y g:i A'),
            $category !== '' ? 'Kategori: ' . $category : null,
            $dun !== '' ? 'DUN: ' . $dun : null,
            'Lokasi: ' . ($case['location_text'] ?: '-'),
            $case['lat'] !== null ? 'Peta: https://www.google.com/maps?q=' . rawurlencode($case['lat'] . ',' . $case['lng']) : null,
            '',
            'Aduan:',
            $case['details'] !== '' ? $case['details'] : '(Tiada keterangan bertulis. Sila rujuk gambar yang dilampirkan.)',
            '',
            $photos ? 'Gambar aduan dilampirkan (' . count($photos) . ').' : null,
            '',
            "Mohon pihak tuan/puan membalas e-mel ini dengan maklum balas tindakan dan nombor rujukan pihak tuan/puan (jika ada), dengan menyebut rujukan $ref.",
            '',
            'Sekian, terima kasih.',
            '',
            $sender_name !== '' ? $sender_name : null,
            Settings::get('mail_from_name'),
            Settings::mail_from(),
        ];
        $headers = ['Reply-To: ' . Settings::mail_from()];
        if ($sender_name !== '') {
            // Sent from info@ (the mailbox SMTP signs in as) under the officer's name; replies come back to info@ and into the case.
            $headers[] = self::FROM_NAME_HEADER . ': ' . str_replace(["\r", "\n"], ' ', $sender_name . ' (MyAduan)');
        }
        if ($agency['cc']) {
            $headers[] = 'Cc: ' . implode(', ', $agency['cc']);
        }
        self::queue(
            "ref:$case_id:{$agency['id']}:" . substr($request_id, 0, 32),
            $agency['email'],
            "Rujukan aduan awam $ref: " . mb_substr($case['title'], 0, 120),
            implode("\n", array_filter($lines, static fn($l) => $l !== null)),
            $case_id,
            $headers,
            $photos,
            isset($agency['referral_id']) ? (int) $agency['referral_id'] : null
        );
    }

    public static function on_created(int $id, string $reference): void
    {
        $case = self::summary($id);
        $complainant = Plugin::crypto()->decrypt($case['email_enc'] ?? null);
        if ($complainant) {
            self::queue(self::ACK . $id, $complainant, "Aduan anda telah diterima: $reference", implode("\n", [
                'Terima kasih. Aduan anda kepada MyAduan Parlimen Gombak telah diterima.',
                '',
                'Nombor rujukan: ' . $reference,
                'Tajuk: ' . $case['title'],
                '',
                'Simpan nombor rujukan ini. Anda boleh semak status aduan pada bila-bila masa di:',
                Settings::page_url('track_path'),
                '(masukkan nombor rujukan atau No. kad pengenalan anda)',
                '',
                'Pegawai kami akan menyemak aduan anda dan menghubungi anda jika perlu.',
                '',
                '— MyAduan Parlimen Gombak',
                'E-mel ini dihantar secara automatik kerana alamat ini diberikan semasa membuat aduan.',
            ]), $id);
        }
        foreach (self::staff_email_on() ? Settings::notify_emails() : [] as $to) {
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
        if (!self::staff_email_on()) {
            return;
        }
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
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET state = IF(attempts >= %d, 'failed', 'pending'), last_error = 'timeout' WHERE state = 'sending' AND updated_at < %s",
            self::MAX_ATTEMPTS, gmdate('Y-m-d H:i:s', time() - 900)
        ));
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
            $headers = json_decode((string) $m['headers'], true) ?: [];
            $name = Settings::get('mail_from_name');
            foreach ($headers as $i => $line) {
                if (stripos($line, self::FROM_NAME_HEADER . ':') === 0) {
                    $name = trim(substr($line, strlen(self::FROM_NAME_HEADER) + 1)) ?: $name;
                    unset($headers[$i]);
                }
            }
            $headers = array_values($headers);
            $from = static fn(): string => Settings::mail_from();
            $from_name = static fn(): string => $name;
            add_action('wp_mail_failed', $catch);
            add_filter('wp_mail_from', $from, 99);
            add_filter('wp_mail_from_name', $from_name, 99);
            $files = [];
            foreach (json_decode((string) $m['attachments'], true) ?: [] as $att_id) {
                $path = $wpdb->get_var($wpdb->prepare('SELECT path FROM ' . Schema::table('attachments') . ' WHERE id = %d', (int) $att_id));
                if ($path && ($file = Files::resolve($path))) {
                    $files[] = $file;
                }
            }
            $headers[] = self::ID_HEADER . ': ' . self::bounce_token((int) $m['id']);
            $ok = wp_mail($m['recipient'], $m['subject'], $m['body'], $headers, $files);
            remove_filter('wp_mail_from', $from, 99);
            remove_filter('wp_mail_from_name', $from_name, 99);
            remove_action('wp_mail_failed', $catch);
            $state = $ok ? 'sent' : ((int) $m['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending');
            $done = ['state' => $state, 'last_error' => $ok ? null : mb_substr($error ?: 'wp_mail gagal', 0, 255), 'updated_at' => Cases::now(), 'sent_at' => $ok ? Cases::now() : null];
            if ($state !== 'pending' && str_starts_with($m['dedupe_key'], self::ACK)) {
                // The complainant's address is kept encrypted on the case only, not in plain text here.
                $done['recipient'] = '';
            }
            $wpdb->update($table, $done, ['id' => $id]);
            $sent += $ok ? 1 : 0;
        }
        return $sent;
    }

    /** "m123-<hmac>": a returned message can only point at a row this site really sent. */
    public static function bounce_token(int $id): string
    {
        return 'm' . $id . '-' . substr(hash_hmac('sha256', 'mail:' . $id, wp_salt('auth')), 0, 12);
    }

    public static function token_id(string $token): ?int
    {
        if (!preg_match('/^m(\d+)-([0-9a-f]{12})$/', $token, $m)) {
            return null;
        }
        return hash_equals(self::bounce_token((int) $m[1]), $token) ? (int) $m[1] : null;
    }

    private static function summary(int $id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id, reference, title, location_text, priority, owner_id, assigned_at, version, email_enc FROM ' . Schema::table('cases') . ' WHERE id = %d', $id), ARRAY_A) ?: [];
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
