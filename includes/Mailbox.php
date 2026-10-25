<?php
namespace MyAduan;

use MyAduan\Rest\FieldErrors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the sender mailbox (info@myaduan.my) over IMAP, every few minutes:
 *
 * - Bounces: every outgoing message carries an X-MyAduan-Id header, and bounce
 *   reports quote the original headers, so the report marks its queue row
 *   "bounced" with the mail server's reason.
 * - Agency replies: a reply that names a case reference and comes from an agency
 *   the case was referred to is added to the case history, and the officer
 *   holding the case is notified in the portal.
 *
 * Messages are only read (BODY.PEEK), never moved, flagged or deleted. The
 * mailbox password is kept encrypted and never sent back to the browser.
 */
final class Mailbox
{
    private const OPTION = 'myaduan_imap';
    private const BATCH = 40;
    private const FETCH_BYTES = 65536;
    /** First run looks this far back only. */
    private const FIRST_DAYS = 7;

    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;

    /** @return array{host:string, port:int, user:string, configured:bool, last_check:?string, last_error:?string, found:int, replies:int} */
    public static function settings(): array
    {
        $o = self::option();
        return [
            'host' => $o['host'],
            'port' => $o['port'],
            'user' => $o['user'],
            'configured' => $o['pass'] !== '',
            'last_check' => $o['last_check'] ? Cases::iso($o['last_check']) : null,
            'last_error' => $o['last_error'] ?: null,
            'found' => (int) $o['found'],
            'replies' => (int) $o['replies'],
        ];
    }

    /** Saves the mailbox details after a successful login. A blank password keeps the saved one. */
    public static function save(mixed $host, mixed $port, mixed $user, mixed $pass): array
    {
        $o = self::option();
        $host = strtolower(trim(sanitize_text_field((string) $host)));
        $port = (int) $port ?: 993;
        $user = sanitize_email((string) $user);
        $errors = [];
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            $errors['host'] = 'Masukkan nama pelayan IMAP, contoh imap.hostinger.com.';
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Port tidak sah.';
        }
        if (!Settings::is_staff_email($user)) {
            $errors['user'] = 'Mailbox mesti @' . Settings::staff_domain() . ', contoh ' . Settings::mail_from() . '.';
        }
        $plain = is_string($pass) && $pass !== '' ? $pass : (string) Plugin::crypto()->decrypt($o['pass'] ?: null);
        if ($plain === '') {
            $errors['pass'] = 'Masukkan kata laluan mailbox.';
        }
        if ($errors) {
            throw new FieldErrors($errors);
        }
        $probe = new self();
        try {
            $probe->connect($host, $port);
            $probe->login($user, $plain);
            $probe->close();
        } catch (\RuntimeException $e) {
            $probe->close();
            throw new FieldErrors(['pass' => 'Tidak dapat log masuk ke mailbox: ' . $e->getMessage()]);
        }
        $changed = $host !== $o['host'] || $user !== $o['user'];
        $o = array_merge($o, [
            'host' => $host, 'port' => $port, 'user' => $user,
            'pass' => (string) Plugin::crypto()->encrypt($plain),
            'last_error' => '',
        ]);
        if ($changed) {
            $o['last_uid'] = 0;
            $o['uidvalidity'] = 0;
        }
        update_option(self::OPTION, $o, false);
        return self::settings();
    }

    public static function forget(): array
    {
        delete_option(self::OPTION);
        return self::settings();
    }

    /** Cron: reads new messages in the mailbox. Returns how many bounces and replies were recorded. */
    public static function check(): int
    {
        $o = self::option();
        if ($o['pass'] === '') {
            return 0;
        }
        $client = new self();
        $marked = 0;
        $replies = 0;
        try {
            $client->connect($o['host'], (int) $o['port']);
            $client->login($o['user'], (string) Plugin::crypto()->decrypt($o['pass']));
            $validity = $client->select('INBOX');
            if ($validity !== (int) $o['uidvalidity']) {
                $o['uidvalidity'] = $validity;
                $o['last_uid'] = 0;
            }
            // Only the first look is limited to recent mail; after that, everything past the cursor is read, however long cron was down.
            $since = (int) $o['last_uid'] === 0 ? ' SINCE ' . gmdate('j-M-Y', time() - self::FIRST_DAYS * DAY_IN_SECONDS) : '';
            $uids = $client->search('UID ' . ((int) $o['last_uid'] + 1) . ':*' . $since);
            // "n:*" always returns the newest message even when it is older than n.
            $uids = array_values(array_filter($uids, static fn(int $u): bool => $u > (int) $o['last_uid']));
            sort($uids);
            foreach (array_slice($uids, 0, self::BATCH) as $uid) {
                $raw = $client->fetch($uid);
                $found = self::parse($raw);
                if ($found) {
                    $marked += self::mark($found['id'], $found['reason']) ? 1 : 0;
                } elseif (self::reply($raw, $validity . ':' . $uid)) {
                    $replies++;
                }
                $o['last_uid'] = max((int) $o['last_uid'], $uid);
            }
            $client->logout();
            $o['last_error'] = '';
        } catch (\Throwable $e) {
            $client->close();
            $o['last_error'] = mb_substr($e->getMessage(), 0, 250);
        }
        $o['last_check'] = Cases::now();
        $o['found'] = (int) $o['found'] + $marked;
        $o['replies'] = (int) $o['replies'] + $replies;
        update_option(self::OPTION, $o, false);
        return $marked + $replies;
    }

    /**
     * Reads a raw message. A delivery failure report that quotes one of our
     * X-MyAduan-Id headers gives the row id and the server's reason.
     *
     * @return array{id:int, reason:string}|null
     */
    public static function parse(string $raw): ?array
    {
        if (!preg_match('/' . preg_quote(Mailer::ID_HEADER, '/') . ':\s*(m\d+-[0-9a-f]{12})/i', $raw, $m)) {
            return null;
        }
        $id = Mailer::token_id(strtolower($m[1]));
        if ($id === null) {
            return null;
        }
        $head = substr($raw, 0, (int) (strpos($raw, "\r\n\r\n") ?: strpos($raw, "\n\n") ?: strlen($raw)));
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
        preg_match('/^From:(.*)$/mi', $head, $from);
        preg_match('/^Subject:(.*)$/mi', $head, $subject);
        $subject = trim(self::decode_header($subject[1] ?? ''));
        $is_report = (bool) preg_match('/multipart\/report;.*report-type="?delivery-status/i', $head)
            || preg_match('/mailer-daemon|postmaster|mail delivery/i', $from[1] ?? '')
            || preg_match('/undeliver|delivery status notification|delivery (has )?failed|failure notice|returned mail|not delivered|tidak dapat dihantar/i', $subject);
        if (!$is_report) {
            return null;
        }
        // Only a failure is a bounce: delay, delivered, relayed and expanded reports are not.
        if (preg_match('/^Action:/mi', $raw) ? !preg_match('/^Action:\s*failed/mi', $raw) : preg_match('/^Status:\s*2\./mi', $raw)) {
            return null;
        }
        $reason = '';
        if (preg_match('/^Diagnostic-Code:\s*(?:smtp;\s*)?(.+(?:\r?\n[ \t]+.+)*)/mi', $raw, $d)) {
            $reason = $d[1];
        } elseif (preg_match('/^Status:\s*(\d\.\d+\.\d+)/mi', $raw, $st)) {
            $reason = 'Status ' . $st[1];
        } elseif (preg_match('/^\s*(5\d\d[ -].{10,})$/m', $raw, $line)) {
            $reason = $line[1];
        }
        $reason = trim(preg_replace('/\s+/', ' ', $reason)) ?: ($subject ?: 'Mesej dikembalikan oleh pelayan e-mel');
        return ['id' => $id, 'reason' => mb_substr($reason, 0, 240)];
    }

    /**
     * An agency's reply about a case: added to the case history and sent to the
     * officer holding the case. Mail from anyone the case was not referred to is ignored.
     */
    public static function reply(string $raw, string $source_id): bool
    {
        global $wpdb;
        $msg = self::message($raw);
        if (!preg_match('/GOM-\d{8}-[0-9A-F]{10}/i', $msg['subject'] . "\n" . $msg['text'], $ref)) {
            return false;
        }
        $case = $wpdb->get_row($wpdb->prepare('SELECT id, reference, owner_id FROM ' . Schema::table('cases') . ' WHERE reference = %s', strtoupper($ref[0])), ARRAY_A);
        if (!$case || $msg['from'] === '' || strcasecmp($msg['from'], Settings::mail_from()) === 0) {
            return false;
        }
        $agency = self::sender_agency((int) $case['id'], $msg['from']);
        if (!$agency) {
            return false;
        }
        $text = trim(mb_substr(self::strip_quoted($msg['text']), 0, 4000));
        $sender = ($msg['name'] !== '' ? $msg['name'] . ' · ' : '') . $agency;
        $added = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . Schema::table('events') . ' (case_id, actor_id, event, status_from, status_to, note, changes, request_id, created_at) VALUES (%d, 0, %s, NULL, NULL, %s, %s, %s, %s)',
            (int) $case['id'], 'AGENCY_REPLY', $text !== '' ? $text : '(tiada teks)',
            wp_json_encode(['sender' => $sender, 'email' => $msg['from'], 'subject' => mb_substr($msg['subject'], 0, 200)], JSON_UNESCAPED_UNICODE),
            hash('sha256', 'reply:' . ($msg['message_id'] ?: $source_id)), Cases::now()
        ));
        if ($added !== 1) {
            return false;
        }
        $owner = (int) $case['owner_id'];
        $to = $owner ? [$owner] : array_map('intval', get_users(['capability' => Access::CAP_MANAGE, 'fields' => 'ID']));
        Notifications::add($to, (int) $case['id'], 'BALASAN_AGENSI', 'Balasan agensi untuk kes ' . $case['reference'], $agency . ': ' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 140));
        return true;
    }

    /** The agency name when the sender is an agency (address, CC or same domain) the case was referred to. */
    private static function sender_agency(int $case_id, string $from): ?string
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT l.name, l.email, l.cc FROM ' . Schema::table('agencies') . ' r JOIN ' . Schema::table('agency_list') . ' l ON l.id = r.agency_id WHERE r.case_id = %d',
            $case_id
        ), ARRAY_A);
        $from = strtolower($from);
        $domain = substr($from, strpos($from, '@') + 1);
        // Free mail domains are shared by everyone, so only the exact address counts there.
        $shared = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'ymail.com', 'icloud.com'];
        foreach ($rows as $r) {
            $addresses = array_filter(array_map('strtolower', array_map('trim', array_merge([$r['email']], explode(',', (string) $r['cc'])))));
            foreach ($addresses as $a) {
                if ($a === $from || (!in_array($domain, $shared, true) && str_ends_with($a, '@' . $domain))) {
                    return $r['name'];
                }
            }
        }
        return null;
    }

    /** Drops the quoted earlier message under a reply. */
    private static function strip_quoted(string $text): string
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (preg_match('/^(On .+wrote:|Pada .+(menulis|tulis):|-{2,} ?(Original Message|Mesej Asal|Forwarded message) ?-{2,}|From: .+|Daripada: .+|_{10,})\s*$/i', trim($line))) {
                break;
            }
            if (!str_starts_with(ltrim($line), '>')) {
                $out[] = $line;
            }
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }

    /**
     * Splits a raw message into what a reply needs: sender, subject, Message-ID and
     * the plain text (from text/plain, or text/html without tags).
     *
     * @return array{from:string, name:string, subject:string, message_id:string, text:string}
     */
    public static function message(string $raw): array
    {
        [$head, $body] = self::split($raw);
        $h = self::headers($head);
        $from = self::decode_header($h['from'] ?? '');
        $email = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from);
        $name = trim(preg_replace('/<[^>]*>/', '', $from), " \t\"'");
        $parts = [];
        self::walk($h, $body, $parts);
        $text = $parts['text/plain'] ?? (isset($parts['text/html']) ? html_entity_decode(wp_strip_all_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $parts['text/html'])), ENT_QUOTES, 'UTF-8') : '');
        return [
            'from' => is_email(trim($email)) ? strtolower(trim($email)) : '',
            'name' => $name === $email ? '' : mb_substr($name, 0, 100),
            'subject' => trim(self::decode_header($h['subject'] ?? '')),
            'message_id' => trim($h['message-id'] ?? ''),
            'text' => str_replace("\r", '', $text),
        ];
    }

    private static function walk(array $h, string $body, array &$parts, int $depth = 0): void
    {
        $type = strtolower(trim(strtok($h['content-type'] ?? 'text/plain', ';')));
        if (str_starts_with($type, 'multipart/') && $depth < 5 && preg_match('/boundary="?([^";]+)"?/i', $h['content-type'] ?? '', $b)) {
            foreach (array_slice(explode('--' . $b[1], $body), 1) as $chunk) {
                if (str_starts_with($chunk, '--')) {
                    break;
                }
                [$ph, $pb] = self::split(ltrim($chunk, "\r\n"));
                self::walk(self::headers($ph), $pb, $parts, $depth + 1);
            }
            return;
        }
        if (($type !== 'text/plain' && $type !== 'text/html') || isset($parts[$type]) || preg_match('/attachment/i', $h['content-disposition'] ?? '')) {
            return;
        }
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $text = $enc === 'base64' ? (string) base64_decode(preg_replace('/\s+/', '', $body)) : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body);
        $charset = preg_match('/charset="?([^";]+)"?/i', $h['content-type'] ?? '', $c) ? strtoupper($c[1]) : 'UTF-8';
        if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $text = (string) @mb_convert_encoding($text, 'UTF-8', $charset);
        }
        $parts[$type] = $text;
    }

    /** @return array{0:string, 1:string} head and body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $at = strpos($raw, "\n\n");
        return $at === false ? [$raw, ''] : [substr($raw, 0, $at), substr($raw, $at + 2)];
    }

    /** @return array<string,string> lower-case header names, first occurrence wins */
    private static function headers(string $head): array
    {
        $out = [];
        foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] ??= trim($v);
            }
        }
        return $out;
    }

    private static function mark(int $id, string $reason): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('mail_queue') . " SET state = 'bounced', last_error = %s, updated_at = %s WHERE id = %d AND state = 'sent'",
            $reason, Cases::now(), $id
        ));
    }

    private static function decode_header(string $v): string
    {
        return function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $v;
    }

    private static function option(): array
    {
        $o = get_option(self::OPTION);
        return array_merge([
            'host' => 'imap.hostinger.com', 'port' => 993, 'user' => Settings::mail_from(), 'pass' => '',
            'last_uid' => 0, 'uidvalidity' => 0, 'last_check' => '', 'last_error' => '', 'found' => 0, 'replies' => 0,
        ], is_array($o) ? $o : []);
    }

    /* ---------- a small IMAP client: TLS socket, LOGIN, SELECT, UID SEARCH, UID FETCH ---------- */

    private function connect(string $host, int $port): void
    {
        $address = (string) apply_filters('myaduan_imap_address', "ssl://$host:$port", $host, $port);
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new \RuntimeException("sambungan ke $host:$port gagal ($errstr)");
        }
        stream_set_timeout($sock, 20);
        $this->sock = $sock;
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK')) {
            throw new \RuntimeException('pelayan IMAP tidak menjawab dengan betul');
        }
    }

    private function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass), 'kata laluan atau nama mailbox salah');
    }

    /** @return int UIDVALIDITY */
    private function select(string $box): int
    {
        $lines = $this->command('EXAMINE ' . self::quote($box));
        foreach ($lines as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /** @return list<int> */
    private function search(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $l) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', $l, $m)) {
                $uids = array_merge($uids, array_map('intval', array_filter(explode(' ', trim($m[1])), 'strlen')));
            }
        }
        return $uids;
    }

    private function fetch(int $uid): string
    {
        $data = '';
        foreach ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . self::FETCH_BYTES . '>)') as $l) {
            if (str_contains($l, "\n")) {
                $data .= substr($l, strpos($l, "\n") + 1);
            }
        }
        return $data;
    }

    private function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (\RuntimeException) {
            // Closing anyway.
        }
        $this->close();
    }

    private function close(): void
    {
        if ($this->sock) {
            fclose($this->sock);
            $this->sock = null;
        }
    }

    /** @return list<string> untagged lines (a literal is kept inside its line after a newline) */
    private function command(string $cmd, string $fail = ''): array
    {
        $tag = 'A' . (++$this->tag);
        if (!$this->sock || fwrite($this->sock, "$tag $cmd\r\n") === false) {
            throw new \RuntimeException('sambungan IMAP terputus');
        }
        $out = [];
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    throw new \RuntimeException($fail ?: 'arahan IMAP ditolak: ' . mb_substr(substr($line, strlen($tag) + 1), 0, 120));
                }
                return $out;
            }
            $out[] = $line;
        }
    }

    /** One response line; a {n} literal is read in full and appended after a newline. */
    private function line(): string
    {
        $line = fgets($this->sock, 8192);
        if ($line === false) {
            throw new \RuntimeException('pelayan IMAP tidak menjawab');
        }
        $line = rtrim($line, "\r\n");
        while (preg_match('/\{(\d+)\}$/', $line, $m)) {
            $want = (int) $m[1];
            $buf = '';
            while (strlen($buf) < $want) {
                $chunk = fread($this->sock, min(8192, $want - strlen($buf)));
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('pelayan IMAP tidak menjawab');
                }
                $buf .= $chunk;
            }
            $rest = fgets($this->sock, 8192);
            $line .= "\n" . $buf . rtrim((string) $rest, "\r\n");
        }
        return $line;
    }

    private static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
