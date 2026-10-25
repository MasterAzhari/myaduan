<?php
use MyAduan\Mailer;
use MyAduan\Schema;
use MyAduan\Settings;
use MyAduan\Tests\Integration\TestCase;

/** Staff accounts on @myaduan.my, and the complainant's acknowledgement e-mail from info@myaduan.my. */
final class EmailTest extends TestCase
{
    private array $mails = [];
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Schema::table('mail_queue'));
        update_option('myaduan_settings', ['notify_emails' => 'urusetia@myaduan.my']);
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created as $id) {
            wp_delete_user($id);
        }
    }

    /** Records each message with the sender WordPress would use for it. */
    public function capture($short, array $atts): bool
    {
        $atts['from'] = apply_filters('wp_mail_from', 'wordpress@localhost');
        $atts['from_name'] = apply_filters('wp_mail_from_name', 'WordPress');
        $this->mails[] = $atts;
        return true;
    }

    public function test_staff_accounts_must_use_the_domain(): void
    {
        [$status, $data] = $this->call('POST', '/admin/users', ['name' => 'Ali Gmail', 'email' => 'ali@gmail.com', 'role' => 'superadmin'], 'super');
        $this->assertSame(422, $status);
        $this->assertStringContainsString('@myaduan.my', $data['data']['fields']['email']);
        $this->assertFalse(email_exists('ali@gmail.com'));
        [$status] = $this->call('POST', '/admin/users', ['name' => 'Ali', 'email' => 'ali@myaduan.my.evil.com', 'role' => 'superadmin'], 'super');
        $this->assertSame(422, $status, 'lookalike domains are refused');

        [$status, $u] = $this->call('POST', '/admin/users', ['name' => 'Ali Urusetia', 'email' => 'Ali.Urusetia@MyAduan.my', 'role' => 'superadmin'], 'super');
        $this->assertSame(201, $status, wp_json_encode($u));
        $this->created[] = $u['id'];
        $this->assertTrue($u['email_ok']);

        // An older account on another domain is flagged and must be moved before any other change saves.
        wp_update_user(['ID' => $u['id'], 'user_email' => 'ali.lama@yahoo.com']);
        [, $list] = $this->call('GET', '/admin/users', [], 'super');
        $row = array_values(array_filter($list, fn($x) => $x['id'] === $u['id']))[0];
        $this->assertFalse($row['email_ok']);
        $this->assertSame(422, $this->call('POST', '/admin/users/' . $u['id'], ['email' => 'ali.lama@yahoo.com', 'name' => 'Ali Baru'], 'super')[0]);
        [$status, $data] = $this->call('POST', '/admin/users/' . $u['id'], ['name' => 'Ali Baru'], 'super');
        $this->assertSame(422, $status, 'a change that leaves out the e-mail still needs the old address moved');
        $this->assertArrayHasKey('email', $data['data']['fields']);
        $this->assertSame(200, $this->call('POST', '/admin/users/' . $u['id'], ['active' => false], 'super')[0], 'deactivating an old account needs no new address');
        $this->assertSame(200, $this->call('POST', '/admin/users/' . $u['id'], ['email' => 'ali@myaduan.my', 'name' => 'Ali Baru', 'active' => true], 'super')[0]);
    }

    public function test_settings_keep_only_domain_addresses(): void
    {
        $clean = Settings::sanitize(['notify_emails' => 'urusetia@myaduan.my, boss@gmail.com, yb@MYADUAN.MY', 'mail_from' => 'noreply@gmail.com']);
        $this->assertSame('urusetia@myaduan.my, yb@MYADUAN.MY', $clean['notify_emails']);
        $this->assertSame('info@myaduan.my', $clean['mail_from']);
        $this->assertSame('aduan@myaduan.my', Settings::sanitize(['mail_from' => 'aduan@myaduan.my'])['mail_from']);

        // Stored values from before the rule are filtered on use too.
        update_option('myaduan_settings', ['notify_emails' => 'boss@gmail.com']);
        $this->assertNotContains('boss@gmail.com', Settings::notify_emails());
        foreach (Settings::notify_emails() as $e) {
            $this->assertTrue(Settings::is_staff_email($e), $e);
        }
    }

    public function test_complainant_gets_reference_from_info_address(): void
    {
        global $wpdb;
        $ref = $this->submit(['email' => ' Siti@Contoh.com '])['reference'];
        $id = $this->case_id($ref);

        $stored = $wpdb->get_var($wpdb->prepare('SELECT email_enc FROM ' . Schema::table('cases') . ' WHERE id = %d', $id));
        $this->assertStringNotContainsString('contoh', $stored, 'stored encrypted');
        [, $case] = $this->call('GET', "/admin/cases/$id", [], 'super');
        $this->assertSame('siti@contoh.com', $case['complainant']['email']);

        Mailer::process();
        $to = array_column($this->mails, 'to');
        $this->assertEqualsCanonicalizing(['urusetia@myaduan.my', 'siti@contoh.com'], $to);
        $ack = $this->mails[array_search('siti@contoh.com', $to, true)];
        $this->assertStringContainsString($ref, $ack['subject']);
        $this->assertStringContainsString($ref, $ack['message']);
        $this->assertStringContainsString(home_url('/semak/'), $ack['message']);
        $this->assertStringNotContainsString('880512', $ack['message'], 'no IC in e-mail');
        $this->assertStringNotContainsString('/adminaduan/', $ack['message'], 'no portal link for the public');
        foreach ($this->mails as $m) {
            $this->assertSame(['info@myaduan.my', 'MyAduan Parlimen Gombak'], [$m['from'], $m['from_name']]);
        }
        $this->assertSame('wordpress@localhost', apply_filters('wp_mail_from', 'wordpress@localhost'), 'sender filter only during MyAduan mail');

        $recipients = array_column($wpdb->get_results('SELECT recipient FROM ' . Schema::table('mail_queue') . " WHERE dedupe_key LIKE 'ack:%'", ARRAY_A), 'recipient');
        $this->assertSame([''], $recipients, 'complainant address wiped from the queue after sending');
    }

    public function test_email_is_optional_and_checked(): void
    {
        global $wpdb;
        $this->submit(['email' => '']);
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('mail_queue') . " WHERE dedupe_key LIKE 'ack:%'"));
        [$status, $data] = $this->call('POST', '/complaints', $this->complaint(['email' => 'bukan-emel']));
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('email', $data['data']['fields']);
    }
}
