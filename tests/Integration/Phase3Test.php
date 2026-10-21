<?php
use MyAduan\Portal;
use MyAduan\Tests\Integration\TestCase;
use MyAduan\Users;

final class Phase3Test extends TestCase
{
    private array $mails = [];
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
        update_option('myaduan_settings', ['portal_path' => 'adminaduan']);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created as $id) {
            wp_delete_user($id);
        }
    }

    public function capture($short, array $atts): bool
    {
        $this->mails[] = $atts;
        return true;
    }

    public function test_portal_path_matching(): void
    {
        $this->assertTrue(Portal::matches('adminaduan'));
        $this->assertTrue(Portal::matches('/adminaduan/'));
        $this->assertTrue(Portal::matches('adminaduan/kes'));
        $this->assertFalse(Portal::matches('adminaduan-lama'));
        $this->assertFalse(Portal::matches('aduan'));
        $this->assertSame('adminaduan', Portal::request_path('/adminaduan/?x=1#/kes'));
    }

    public function test_portal_requires_login_and_access(): void
    {
        $r = Portal::response(new WP_User(0));
        $this->assertSame(200, $r['status'], 'logged out: a page that keeps the #route and goes to login');
        preg_match('#id="myaduan-config">(.*?)</script>#', $r['body'], $m);
        $this->assertSame(['login' => wp_login_url(), 'portal' => home_url('/adminaduan/')], json_decode($m[1], true));
        $this->assertStringContainsString(esc_url(wp_login_url(home_url('/adminaduan/'))), $r['body'], 'noscript fallback');

        $sub = $this->make_user('t_subscriber', 'subscriber');
        $r = Portal::response(new WP_User($sub));
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('Tiada akses', $r['body']);

        wp_set_current_user(self::$users['a01']);
        $r = Portal::response(new WP_User(self::$users['a01']));
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('assets/admin.js', $r['body']);
        $this->assertStringContainsString("frame-ancestors 'none'", $r['headers']['Content-Security-Policy']);
        preg_match('#<script type="application/json" id="myaduan-config">(.*?)</script>#', $r['body'], $m);
        $config = json_decode($m[1], true);
        $this->assertSame(rest_url('myaduan/v1'), $config['api']);
        $this->assertSame(1, wp_verify_nonce($config['nonce'], 'wp_rest'));
        $this->assertStringNotContainsString('&amp;', $config['logout']);
    }

    public function test_portal_only_accounts_land_in_portal(): void
    {
        $officer = new WP_User(self::$users['a01']);
        $this->assertSame(home_url('/adminaduan/'), Portal::login_redirect(admin_url(), '', $officer));
        $this->assertSame(home_url('/adminaduan/#/kes/5'), Portal::login_redirect(home_url('/adminaduan/#/kes/5'), home_url('/adminaduan/#/kes/5'), $officer));
        $admin = new WP_User($this->make_user('t_siteadmin', 'administrator'));
        $this->assertSame(admin_url(), Portal::login_redirect(admin_url(), '', $admin));
        $this->assertTrue(Portal::portal_only($officer));
        $this->assertFalse(Portal::portal_only($admin));
    }

    public function test_only_super_admin_manages_users(): void
    {
        $this->assertSame(401, $this->call('GET', '/admin/users')[0]);
        $this->assertSame(403, $this->call('GET', '/admin/users', [], 'a01')[0]);
        $this->assertSame(403, $this->call('POST', '/admin/users', ['name' => 'X', 'email' => 'x@myaduan.my', 'role' => 'superadmin'], 'a01')[0]);
        [$status, $users] = $this->call('GET', '/admin/users', [], 'super');
        $this->assertSame(200, $status);
        $logins = array_column($users, 'login');
        $this->assertContains('t_officer_a01', $logins);
        $this->assertContains('t_viewer', $logins);
    }

    public function test_create_officer_sends_password_link(): void
    {
        [$status, $u] = $this->call('POST', '/admin/users', [
            'name' => 'Ahmad Pegawai', 'email' => 'ahmad.pegawai@myaduan.my', 'role' => 'officer',
            'policy' => ['mode' => 'selected', 'codes' => ['A04', 'A01', 'Z99']],
        ], 'super');
        $this->assertSame(201, $status, wp_json_encode($u));
        $this->created[] = $u['id'];
        $this->assertSame('ahmad.pegawai', $u['login']);
        $this->assertSame(['mode' => 'selected', 'codes' => ['A04', 'A01']], $u['policy'], 'unknown categories dropped');
        $this->assertTrue($u['active']);
        $this->assertContains('myaduan_officer', (new WP_User($u['id']))->roles);
        $this->assertCount(1, $this->mails);
        $this->assertSame('ahmad.pegawai@myaduan.my', $this->mails[0]['to']);

        // The new officer can be assigned a case in their category straight away.
        $id = $this->case_id($this->submit()['reference']);
        [$s] = $this->op('super', $id, 'assign', ['owner_id' => $u['id'], 'category_code' => 'A04']);
        $this->assertSame(200, $s);
    }

    public function test_create_validation(): void
    {
        [$status, $data] = $this->call('POST', '/admin/users', ['name' => 'A', 'email' => 't_super@myaduan.my', 'role' => 'boss'], 'super');
        $this->assertSame(422, $status);
        $this->assertSame(['name', 'email', 'role'], array_keys($data['data']['fields']));
        [$status, $data] = $this->call('POST', '/admin/users', ['name' => 'Tiada Kategori', 'email' => 'tiada@myaduan.my', 'role' => 'officer', 'policy' => ['mode' => 'selected', 'codes' => []]], 'super');
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('policy', $data['data']['fields']);
        $this->assertFalse(email_exists('tiada@myaduan.my'));
    }

    public function test_deactivate_blocked_while_holding_open_cases(): void
    {
        $officer = $this->new_officer('ali@myaduan.my', ['mode' => 'all']);
        $id = $this->case_id($this->submit()['reference']);
        $this->op('super', $id, 'assign', ['owner_id' => $officer, 'category_code' => 'A02']);

        [$status, $data] = $this->call('POST', "/admin/users/$officer", ['active' => false], 'super');
        $this->assertSame(409, $status);
        $this->assertStringContainsString('1 kes terbuka', $data['message']);
        [$status] = $this->call('POST', "/admin/users/$officer", ['policy' => ['mode' => 'selected', 'codes' => ['A01']]], 'super');
        $this->assertSame(409, $status, 'removing the category of a held case is blocked too');
        [$status] = $this->call('POST', "/admin/users/$officer", ['policy' => ['mode' => 'selected', 'codes' => ['A02', 'A03']]], 'super');
        $this->assertSame(200, $status, 'keeping the category is fine');

        $this->op('super', $id, 'assign', ['owner_id' => self::$users['a02']]);
        [$status, $u] = $this->call('POST', "/admin/users/$officer", ['active' => false], 'super');
        $this->assertSame(200, $status, wp_json_encode($u));
        $this->assertFalse($u['active']);
        $this->assertSame('officer', $u['role'], 'role remembered while inactive');
        $this->assertFalse(user_can($officer, 'myaduan_access'));
        [, $list] = $this->call('GET', '/admin/users', [], 'super');
        $this->assertContains($officer, array_column($list, 'id'), 'inactive users stay listed');
        [, $catalog] = $this->call('GET', '/admin/catalog', [], 'super');
        $this->assertNotContains($officer, array_column($catalog['officers'], 'id'), 'inactive users cannot be assigned');

        [$status, $u] = $this->call('POST', "/admin/users/$officer", ['active' => true], 'super');
        $this->assertSame(200, $status);
        $this->assertTrue($u['active'] && user_can($officer, 'myaduan_work_cases'));
    }

    public function test_site_admin_and_self_are_protected(): void
    {
        $admin = $this->make_user('t_siteadmin2', 'administrator');
        $this->assertSame(404, $this->call('POST', "/admin/users/$admin", ['name' => 'Ubah'], 'super')[0], 'administrators are not MyAduan-managed accounts');
        update_user_meta($admin, Users::INACTIVE_META, 'myaduan_officer');
        $this->assertSame(403, $this->call('POST', "/admin/users/$admin", ['name' => 'Ubah'], 'super')[0]);
        [$status, $data] = $this->call('POST', '/admin/users/' . self::$users['super'], ['role' => 'viewer'], 'super');
        $this->assertSame(403, $status);
        $this->assertStringContainsString('akaun sendiri', $data['message']);
        $this->assertSame(404, $this->call('POST', '/admin/users/999999', ['name' => 'Tiada'], 'super')[0]);
    }

    public function test_other_wordpress_roles_are_kept(): void
    {
        $officer = $this->new_officer('editor.pegawai@myaduan.my', ['mode' => 'all']);
        (new WP_User($officer))->add_role('editor');
        [$status] = $this->call('POST', "/admin/users/$officer", ['name' => 'Nama Baru', 'role' => 'viewer'], 'super');
        $this->assertSame(200, $status);
        clean_user_cache($officer);
        $this->assertEqualsCanonicalizing(['editor', 'myaduan_viewer'], (new WP_User($officer))->roles);
        $this->call('POST', "/admin/users/$officer", ['active' => false], 'super');
        clean_user_cache($officer);
        $this->assertSame(['editor'], array_values((new WP_User($officer))->roles));
        $this->assertInstanceOf(WP_User::class, \MyAduan\Users::block_inactive(new WP_User($officer)), 'still an editor, so login stays allowed');
    }

    public function test_deactivated_account_cannot_log_in(): void
    {
        $officer = $this->new_officer('keluar@myaduan.my', ['mode' => 'all']);
        wp_set_password('Kata-Laluan-123', $officer);
        $login = get_userdata($officer)->user_login;
        $this->assertInstanceOf(WP_User::class, wp_authenticate($login, 'Kata-Laluan-123'));
        $this->call('POST', "/admin/users/$officer", ['active' => false], 'super');
        clean_user_cache($officer);
        $result = wp_authenticate($login, 'Kata-Laluan-123');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('myaduan_inactive', $result->get_error_code());
        $this->call('POST', "/admin/users/$officer", ['active' => true], 'super');
        clean_user_cache($officer);
        $this->assertInstanceOf(WP_User::class, wp_authenticate($login, 'Kata-Laluan-123'));
    }

    public function test_promote_officer_to_viewer_clears_policy(): void
    {
        $officer = $this->new_officer('siti.v@myaduan.my', ['mode' => 'selected', 'codes' => ['A05']]);
        [$status, $u] = $this->call('POST', "/admin/users/$officer", ['role' => 'viewer', 'name' => 'Siti Pemerhati'], 'super');
        $this->assertSame(200, $status, wp_json_encode($u));
        $this->assertSame(['viewer', 'Siti Pemerhati', 'none'], [$u['role'], $u['name'], $u['policy']['mode']]);
        $this->assertFalse(user_can($officer, 'myaduan_work_cases'));
    }

    public function test_list_flags_and_my_open_count(): void
    {
        $a = $this->case_id($this->submit()['reference']);
        $b = $this->case_id($this->submit(['ic' => '900101-14-5678'])['reference']);
        $this->op('super', $a, 'assign', ['owner_id' => self::$users['a01'], 'category_code' => 'A01']);
        $this->op('super', $b, 'assign', ['owner_id' => self::$users['a01'], 'category_code' => 'A01']);
        $this->op('a01', $b, 'accept');
        $past = (new DateTimeImmutable('-2 days', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d\TH:i');
        [$s, $d] = $this->op('a01', $b, 'action', ['note' => 'Lawatan tapak telah dibuat.', 'followup_at' => $past]);
        $this->assertSame(200, $s, wp_json_encode($d));

        $ids = fn(array $q) => array_column($this->call('GET', '/admin/cases', $q, 'super')[1]['items'], 'id');
        $this->assertSame([$a], $ids(['flag' => 'not_accepted']));
        $this->assertSame([$b], $ids(['flag' => 'overdue']));
        $this->assertCount(2, $ids(['flag' => 'open']));
        $this->assertSame(400, $this->call('GET', '/admin/cases', ['flag' => 'semua'], 'super')[0]);

        [, $stats] = $this->call('GET', '/admin/stats', [], 'a01');
        $this->assertSame(2, $stats['mine_open']);
        $this->assertSame(1, $stats['overdue']);
    }

    private function make_user(string $login, string $role): int
    {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@myaduan.my', 'role' => $role]);
        $this->assertIsInt($id);
        $this->created[] = $id;
        return $id;
    }

    private function new_officer(string $email, array $policy): int
    {
        [$status, $u] = $this->call('POST', '/admin/users', ['name' => 'Pegawai Ujian', 'email' => $email, 'role' => 'officer', 'policy' => $policy], 'super');
        $this->assertSame(201, $status, wp_json_encode($u));
        $this->created[] = $u['id'];
        return $u['id'];
    }
}
