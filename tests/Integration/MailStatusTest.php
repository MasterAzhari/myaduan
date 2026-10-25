<?php
use MyAduan\Mailbox;
use MyAduan\Mailer;
use MyAduan\Schema;
use MyAduan\Tests\Integration\TestCase;

/** v0.6.0: mail goes out at once, every message has a status, bounces and agency replies come back from info@. */
final class MailStatusTest extends TestCase
{
    private array $mails = [];
    private bool $fail = false;
    /** @var resource|null */
    private $imap = null;
    private string $imap_dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        update_option('myaduan_settings', ['portal_path' => 'adminaduan']);
        delete_option('myaduan_imap');
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        remove_all_filters('myaduan_imap_address');
        delete_option('myaduan_imap');
        if ($this->imap) {
            proc_terminate($this->imap);
            proc_close($this->imap);
        }
    }

    public function capture($short, array $atts): bool
    {
        if ($this->fail) {
            do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: Could not authenticate.'));
            return false;
        }
        $atts['from_name'] = apply_filters('wp_mail_from_name', 'WordPress');
        $atts['from'] = apply_filters('wp_mail_from', 'wordpress@localhost');
        $this->mails[] = $atts;
        return true;
    }

    private function referred_case(): array
    {
        global $wpdb;
        $id = $this->case_id($this->submit()['reference']);
        $this->op('super', $id, 'assign', ['category_code' => 'A01', 'owner_id' => self::$users['a01']]);
        $mps = (int) $wpdb->get_var("SELECT id FROM " . Schema::table('agency_list') . " WHERE name = 'Majlis Perbandaran Selayang (MPS)'");
        [$status, $c] = $this->op('a01', $id, 'refer', ['agency_ids' => [$mps], 'note' => 'Mohon tindakan segera pihak MPS.']);
        $this->assertSame(200, $status, wp_json_encode($c));
        return $c;
    }

    private function mail_row(int $case_id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('mail_queue') . " WHERE case_id = %d AND dedupe_key LIKE 'ref:%%'", $case_id), ARRAY_A);
    }

    public function test_referral_goes_out_at_once_under_the_officers_name_with_a_status(): void
    {
        $c = $this->referred_case();
        $this->assertSame('pending', $c['referrals'][0]['mail']['state']);
        $this->assertSame('Belum dihantar', $c['referrals'][0]['mail']['label']);
        $this->assertCount(0, $this->mails, 'nothing before the response is sent');

        $this->assertSame(99, has_action('shutdown', [Mailer::class, 'flush']));
        Mailer::flush();
        $this->assertCount(1, $this->mails, 'sent at the end of the same request, no cron wait');
        $m = $this->mails[0];
        $this->assertSame('ydp@mps.gov.my', $m['to']);
        $this->assertSame('info@myaduan.my', $m['from']);
        $name = get_userdata(self::$users['a01'])->display_name;
        $this->assertSame($name . ' (MyAduan)', $m['from_name']);
        $this->assertContains('Reply-To: info@myaduan.my', $m['headers']);
        $this->assertSame([], array_values(array_filter($m['headers'], static fn($h) => stripos($h, 'X-MyAduan-From-Name') === 0)), 'internal header not sent');
        $id_header = array_values(array_filter($m['headers'], static fn($h) => stripos($h, Mailer::ID_HEADER . ':') === 0))[0];
        $row = $this->mail_row($c['id']);
        $this->assertSame(Mailer::ID_HEADER . ': ' . Mailer::bounce_token((int) $row['id']), $id_header);
        $this->assertStringContainsString($name, $m['message'], 'signed by the officer');
        $this->assertSame('MyAduan Parlimen Gombak', apply_filters('wp_mail_from_name', 'MyAduan Parlimen Gombak'), 'filters removed after sending');

        [, $c] = $this->call('GET', '/admin/cases/' . $c['id'], [], 'super');
        $this->assertSame(['sent', 'Dihantar'], [$c['referrals'][0]['mail']['state'], $c['referrals'][0]['mail']['label']]);
        $this->assertNotNull($row['sent_at']);
    }

    public function test_failed_mail_shows_the_error_and_can_be_retried(): void
    {
        $c = $this->referred_case();
        $this->fail = true;
        Mailer::process();
        [, $c2] = $this->call('GET', '/admin/cases/' . $c['id'], [], 'a01');
        $this->assertSame('pending', $c2['referrals'][0]['mail']['state']);
        $this->assertSame('Belum dihantar (cuba semula)', $c2['referrals'][0]['mail']['label']);
        Mailer::process();
        Mailer::process();
        [, $c2] = $this->call('GET', '/admin/cases/' . $c['id'], [], 'a01');
        $this->assertSame(['failed', 'SMTP Error: Could not authenticate.'], [$c2['referrals'][0]['mail']['state'], $c2['referrals'][0]['mail']['error']]);

        $row = $this->mail_row($c['id']);
        $this->assertSame(403, $this->call('POST', '/admin/mail/' . $row['id'] . '/retry', [], 'a01')[0]);
        [$status, $log] = $this->call('GET', '/admin/mail', ['state' => 'failed'], 'super');
        $this->assertSame(200, $status);
        $this->assertSame([(int) $row['id']], array_column($log['items'], 'id'));
        $this->assertTrue($log['items'][0]['can_retry']);
        $this->assertSame('Agensi', $log['items'][0]['kind']);
        $this->assertSame(1, $log['counts']['failed']);

        $this->fail = false;
        $this->assertSame(200, $this->call('POST', '/admin/mail/' . $row['id'] . '/retry', [], 'super')[0]);
        Mailer::process();
        $this->assertSame('sent', $this->mail_row($c['id'])['state']);
        $this->assertSame(422, $this->call('POST', '/admin/mail/' . $row['id'] . '/retry', [], 'super')[0], 'only failed or bounced');
    }

    public function test_test_mail_reports_its_status(): void
    {
        [$status, $r] = $this->call('POST', '/admin/mail/test', ['to' => 'mmbsb01@gmail.com'], 'super');
        $this->assertSame(200, $status, wp_json_encode($r));
        $this->assertSame('sent', $r['status']['state']);
        $this->assertSame('mmbsb01@gmail.com', end($this->mails)['to']);
        $this->assertSame(422, $this->call('POST', '/admin/mail/test', ['to' => 'bukan emel'], 'super')[0]);
        $this->assertSame(403, $this->call('POST', '/admin/mail/test', ['to' => 'a@b.my'], 'a01')[0]);
    }

    public function test_complainant_address_is_never_listed(): void
    {
        global $wpdb;
        $this->submit(['email' => 'siti@contoh.com']);
        $this->fail = true;
        Mailer::process();
        // A server error that quotes the address, as SMTP rejections and bounce reasons do.
        $wpdb->query('UPDATE ' . Schema::table('mail_queue') . " SET last_error = 'SMTP Error: 550 <siti@contoh.com> mailbox unavailable' WHERE dedupe_key LIKE 'ack:%'");
        [, $log] = $this->call('GET', '/admin/mail', [], 'super');
        $ack = array_values(array_filter($log['items'], static fn($m) => $m['kind'] === 'Pengadu'))[0];
        $this->assertSame('Pengadu', $ack['recipient']);
        $this->assertStringNotContainsString('siti@contoh.com', wp_json_encode($log));
        $this->assertSame('SMTP Error: 550 <[e-mel pengadu]> mailbox unavailable', $ack['status']['error']);
        $this->assertFalse($ack['can_retry']);
    }

    private function dsn(string $token, string $action = 'failed'): string
    {
        return "From: Mail Delivery System <MAILER-DAEMON@mx.hostinger.com>\r\nTo: info@myaduan.my\r\nSubject: Undelivered Mail Returned to Sender\r\n"
            . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"B1\"\r\n\r\n"
            . "--B1\r\nContent-Type: text/plain\r\n\r\nThis is the mail system at host mx.hostinger.com.\r\n\r\n"
            . "--B1\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.hostinger.com\r\n\r\nFinal-Recipient: rfc822; tiada@mps.gov.my\r\nAction: $action\r\nStatus: 5.1.1\r\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 <tiada@mps.gov.my>: Recipient address rejected:\r\n User unknown in virtual mailbox table\r\n\r\n"
            . "--B1\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: Tarmizi (MyAduan) <info@myaduan.my>\r\nSubject: Rujukan aduan awam\r\n" . Mailer::ID_HEADER . ": $token\r\n\r\n--B1--\r\n";
    }

    public function test_bounce_reports_are_recognised(): void
    {
        $token = Mailer::bounce_token(42);
        $this->assertSame(['id' => 42, 'reason' => '550 5.1.1 <tiada@mps.gov.my>: Recipient address rejected: User unknown in virtual mailbox table'], Mailbox::parse($this->dsn($token)));
        $this->assertNull(Mailbox::parse($this->dsn($token, 'delayed')), 'a delay is not a bounce');
        $this->assertNull(Mailbox::parse(str_replace('Status: 5.1.1', 'Status: 2.0.0', $this->dsn($token, 'delivered'))), 'a success report is not a bounce');
        $this->assertNull(Mailbox::parse($this->dsn($token, 'relayed')));
        $this->assertNull(Mailbox::parse($this->dsn('m42-000000000000')), 'forged token');
        $this->assertNull(Mailbox::parse("From: someone@mps.gov.my\r\nSubject: Re: aduan\r\n\r\n" . Mailer::ID_HEADER . ": $token\r\n"), 'not a delivery report');
    }

    private function reply(string $from, string $ref, string $msgid = '<r1@mps.gov.my>'): string
    {
        return "From: Encik Ali <$from>\r\nTo: info@myaduan.my\r\nSubject: Re: Rujukan aduan awam $ref: Lampu jalan\r\nMessage-ID: $msgid\r\n"
            . "Content-Type: multipart/alternative; boundary=\"X\"\r\n\r\n--X\r\nContent-Type: text/plain; charset=\"iso-8859-1\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "Tuan,\r\n\r\nAduan telah diterima. No. rujukan MPS: MPS/2026/88. Kerja pembaikan dijadualkan minggu =\r\nini.\r\n\r\nPada 25 Okt 2026, MyAduan menulis:\r\n> Rujukan aduan awam $ref\r\n> butiran lama\r\n"
            . "--X\r\nContent-Type: text/html\r\n\r\n<p>versi html</p>\r\n--X--\r\n";
    }

    public function test_agency_reply_goes_into_the_case_and_to_the_officer(): void
    {
        global $wpdb;
        $c = $this->referred_case();
        $ref = $c['reference'];
        $this->assertFalse(Mailbox::reply($this->reply('orang.lain@gmail.com', $ref), '7:1'), 'strangers are ignored');
        $this->assertTrue(Mailbox::reply($this->reply('ali@mps.gov.my', $ref), '7:2'), 'same domain as the agency');
        $this->assertFalse(Mailbox::reply($this->reply('ali@mps.gov.my', $ref), '7:3'), 'same Message-ID once only');
        $this->assertFalse(Mailbox::reply($this->reply('ali@mps.gov.my', 'GOM-20990101-ABCDEF1234', '<r2@mps.gov.my>'), '7:4'), 'unknown reference');

        [, $case] = $this->call('GET', '/admin/cases/' . $c['id'], [], 'super');
        $ev = $case['events'][0];
        $this->assertSame('AGENCY_REPLY', $ev['event']);
        $this->assertSame('Encik Ali · Majlis Perbandaran Selayang (MPS)', $ev['actor_name']);
        $this->assertSame("Tuan,\n\nAduan telah diterima. No. rujukan MPS: MPS/2026/88. Kerja pembaikan dijadualkan minggu ini.", $ev['note'], 'quoted part dropped');
        $this->assertSame('ali@mps.gov.my', $ev['changes']['email']);

        $n = $wpdb->get_results($wpdb->prepare('SELECT user_id, kind, title FROM ' . Schema::table('notifications') . " WHERE case_id = %d AND kind = 'BALASAN_AGENSI'", $c['id']), ARRAY_A);
        $this->assertSame([[(string) self::$users['a01'], 'BALASAN_AGENSI', "Balasan agensi untuk kes $ref"]], array_map('array_values', $n), 'the officer holding the case');
        $this->assertNotContains('reply', $case['allowed_operations']);
    }

    public function test_mailbox_check_reads_bounces_and_replies_over_imap(): void
    {
        $c = $this->referred_case();
        Mailer::process();
        $row = $this->mail_row($c['id']);
        $this->start_imap([
            '1.eml' => "From: x@y.com\r\nSubject: Promosi\r\n\r\nbukan berkaitan\r\n",
            '2.eml' => $this->dsn(Mailer::bounce_token((int) $row['id'])),
            '3.eml' => $this->reply('ydp@mps.gov.my', $c['reference']),
        ]);

        [$status, $err] = $this->call('POST', '/admin/mailbox', ['host' => 'imap.hostinger.com', 'port' => 993, 'user' => 'info@myaduan.my', 'pass' => 'salah'], 'super');
        $this->assertSame(422, $status);
        $this->assertStringContainsString('kata laluan', $err['data']['fields']['pass']);
        $this->assertSame(403, $this->call('POST', '/admin/mailbox', ['pass' => 'Rahsia-123'], 'a01')[0]);
        [$status, $box] = $this->call('POST', '/admin/mailbox', ['host' => 'imap.hostinger.com', 'port' => 993, 'user' => 'info@myaduan.my', 'pass' => 'Rahsia-123'], 'super');
        $this->assertSame(200, $status, wp_json_encode($box));
        $this->assertTrue($box['configured']);
        $this->assertStringNotContainsString('Rahsia-123', wp_json_encode($box) . wp_json_encode(get_option('myaduan_imap')), 'password stored encrypted, never returned');

        [$status, $r] = $this->call('POST', '/admin/mail/run', [], 'super');
        $this->assertSame(200, $status, wp_json_encode($r));
        $this->assertSame(2, $r['found']);
        $this->assertNull($r['mailbox']['last_error']);
        $this->assertSame([1, 1], [$r['mailbox']['found'], $r['mailbox']['replies']]);
        $row = $this->mail_row($c['id']);
        $this->assertSame('bounced', $row['state']);
        $this->assertStringContainsString('User unknown', $row['last_error']);

        $this->assertSame(0, Mailbox::check(), 'messages already read are skipped');
        $searches = file($this->imap_dir . '.log', FILE_IGNORE_NEW_LINES);
        $this->assertMatchesRegularExpression('/^UID SEARCH UID 1:\* SINCE \d+-\w+-\d{4}$/', $searches[0], 'first look: recent mail only');
        $this->assertSame('UID SEARCH UID 4:*', end($searches), 'later: everything after the cursor, no date limit');
        [, $case] = $this->call('GET', '/admin/cases/' . $c['id'], [], 'a01');
        $this->assertSame('bounced', $case['referrals'][0]['mail']['state']);
        $this->assertSame(1, count(array_filter($case['events'], static fn($e) => $e['event'] === 'AGENCY_REPLY')));
    }

    private function start_imap(array $files): void
    {
        $this->imap_dir = sys_get_temp_dir() . '/imap-' . uniqid();
        mkdir($this->imap_dir);
        foreach ($files as $name => $body) {
            file_put_contents("$this->imap_dir/$name", $body);
        }
        $port = random_int(20000, 40000);
        $this->imap = proc_open(['python3', __DIR__ . '/../fixtures/fake_imap.py', (string) $port, $this->imap_dir, 'Rahsia-123'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertSame("ready\n", fgets($pipes[1]));
        add_filter('myaduan_imap_address', static fn() => "tcp://127.0.0.1:$port");
    }
}
