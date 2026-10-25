<?php
use MyAduan\Tests\Integration\TestCase;
use MyAduan\Users;

/** v0.5.2: the Super Admin sets and resets passwords; everyone changes their own in the portal. */
final class PasswordTest extends TestCase
{
    private array $created = [];
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('send_auth_cookies', '__return_false');
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('send_auth_cookies', '__return_false');
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created as $key => $id) {
            wp_delete_user($id);
            unset(self::$users[$key]);
        }
    }

    public function capture($short, array $atts): bool
    {
        $this->mails[] = $atts;
        return true;
    }

    public function test_password_rules_on_create(): void
    {
        foreach (['pendek1', 'tiadanombor', '12345678', ' Rahsia1234'] as $bad) {
            [$status, $data] = $this->call('POST', '/admin/users', ['name' => 'Pemerhati', 'email' => 'pemerhati@myaduan.my', 'password' => $bad, 'role' => 'viewer'], 'super');
            $this->assertSame(422, $status, $bad);
            $this->assertSame(['password'], array_keys($data['data']['fields']), $bad);
        }
        [$status, $data] = $this->call('POST', '/admin/users', ['name' => 'Pemerhati', 'email' => 'pemerhati@gmail.com', 'password' => 'Rahsia1234', 'role' => 'viewer'], 'super');
        $this->assertSame(422, $status, 'staff domain still required');
        $this->assertSame(['email'], array_keys($data['data']['fields']));
        $this->assertFalse(email_exists('pemerhati@myaduan.my'));
    }

    public function test_super_admin_resets_a_forgotten_password(): void
    {
        $id = $this->staff('lupa', 'myaduan_viewer', 'LamaPass11');
        $sessions = WP_Session_Tokens::get_instance($id);
        $sessions->create(time() + 3600);
        $this->assertCount(1, $sessions->get_all());

        [$status] = $this->call('POST', '/admin/users/' . $id, ['password' => 'BaruPass22'], 'a01');
        $this->assertSame(403, $status, 'officers cannot reset passwords');

        [$status, $u] = $this->call('POST', '/admin/users/' . $id, ['password' => 'BaruPass22'], 'super');
        $this->assertSame(200, $status, wp_json_encode($u));
        $this->assertInstanceOf(WP_Error::class, wp_authenticate('lupa@myaduan.my', 'LamaPass11'));
        $this->assertInstanceOf(WP_User::class, wp_authenticate('lupa@myaduan.my', 'BaruPass22'));
        $this->assertCount(0, $sessions->get_all(), 'signed out everywhere');
        $this->assertSame('viewer', $u['role'], 'role untouched');
        $this->assertTrue($u['active']);

        // Blank keeps the password; a weak one is refused.
        [$status] = $this->call('POST', '/admin/users/' . $id, ['name' => 'Lupa Lagi', 'password' => ''], 'super');
        $this->assertSame(200, $status);
        $this->assertInstanceOf(WP_User::class, wp_authenticate('lupa@myaduan.my', 'BaruPass22'));
        [$status, $data] = $this->call('POST', '/admin/users/' . $id, ['password' => 'abc'], 'super');
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('password', $data['data']['fields']);

        // A Super Admin changes their own password on the Profile page, not here.
        [$status, $data] = $this->call('POST', '/admin/users/' . self::$users['super'], ['password' => 'BaruPass22'], 'super');
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('password', $data['data']['fields']);
    }

    public function test_everyone_changes_their_own_password(): void
    {
        foreach (['myaduan_superadmin' => 'sendiri.sa', 'myaduan_officer' => 'sendiri.admin', 'myaduan_viewer' => 'sendiri.pemerhati'] as $role => $login) {
            $id = $this->staff($login, $role, 'LamaPass11');
            $other = WP_Session_Tokens::get_instance($id);
            $old_token = $other->create(time() + 3600);

            [$status, $data] = $this->call('POST', '/admin/me/password', ['current' => 'Salah1234', 'password' => 'BaruPass22'], $login);
            $this->assertSame(422, $status);
            $this->assertSame(['current'], array_keys($data['data']['fields']));
            [$status, $data] = $this->call('POST', '/admin/me/password', ['current' => 'LamaPass11', 'password' => 'LamaPass11'], $login);
            $this->assertSame(422, $status, 'must differ');
            [$status, $data] = $this->call('POST', '/admin/me/password', ['current' => 'LamaPass11', 'password' => 'pendek'], $login);
            $this->assertSame(['password'], array_keys($data['data']['fields']));

            [$status, $data] = $this->call('POST', '/admin/me/password', ['current' => 'LamaPass11', 'password' => 'BaruPass22'], $login);
            $this->assertSame(200, $status, $role . ' ' . wp_json_encode($data));
            $this->assertInstanceOf(WP_User::class, wp_authenticate($login . '@myaduan.my', 'BaruPass22'));
            $this->assertInstanceOf(WP_Error::class, wp_authenticate($login . '@myaduan.my', 'LamaPass11'));
            $this->assertNull(WP_Session_Tokens::get_instance($id)->get($old_token), 'other sessions ended');
            $this->assertLessThanOrEqual(1, count(WP_Session_Tokens::get_instance($id)->get_all()), 'only the fresh login of this browser');
        }
        $this->assertSame(401, $this->call('POST', '/admin/me/password', ['current' => 'x', 'password' => 'BaruPass22'])[0]);
        [, $me] = $this->call('GET', '/admin/me', [], 'sendiri.admin');
        $this->assertSame('sendiri.admin@myaduan.my', $me['email']);
    }

    public function test_no_emailed_reset_links_for_staff(): void
    {
        $id = $this->staff('lupa.emel', 'myaduan_officer', 'LamaPass11');
        $this->assertInstanceOf(WP_Error::class, apply_filters('allow_password_reset', true, $id));
        $result = retrieve_password('lupa.emel@myaduan.my');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('Super Admin', $result->get_error_message());
        $this->assertCount(0, $this->mails);

        $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if ($admin) {
            $this->assertTrue(apply_filters('allow_password_reset', true, (int) $admin[0]), 'site admins keep the WordPress reset');
        }
        $link = apply_filters('lost_password_html_link', '<a href="#">Lost your password?</a>');
        $this->assertStringContainsString('Minta Super Admin', $link);
        $this->assertStringContainsString('Lost your password?', $link, 'site admins still reach the WordPress reset');
    }

    public function test_profile_cannot_move_staff_off_the_domain(): void
    {
        $id = $this->staff('profil', 'myaduan_viewer', 'LamaPass11');
        $errors = new WP_Error();
        do_action_ref_array('user_profile_update_errors', [&$errors, true, (object) ['ID' => $id, 'user_email' => 'profil@gmail.com']]);
        $this->assertSame(['email'], $errors->get_error_codes());
        $errors = new WP_Error();
        do_action_ref_array('user_profile_update_errors', [&$errors, true, (object) ['ID' => $id, 'user_email' => 'profil2@myaduan.my']]);
        $this->assertFalse($errors->has_errors());
    }

    public function test_api_answers_are_never_cached(): void
    {
        $fired = 0;
        $count = static function () use (&$fired) { $fired++; };
        add_action('litespeed_control_set_nocache', $count);
        wp_set_current_user(self::$users['super']);
        $req = new WP_REST_Request('GET', '/myaduan/v1/admin/cases');
        $req->set_query_params(['_' => 'abc']);
        $res = rest_get_server()->dispatch($req);
        $res = apply_filters('rest_post_dispatch', $res, rest_get_server(), $req);
        remove_action('litespeed_control_set_nocache', $count);
        $this->assertSame(200, $res->get_status(), 'cache-busting parameter is ignored');
        $this->assertStringContainsString('no-store', $res->get_headers()['Cache-Control']);
        $this->assertSame('no-cache', $res->get_headers()['X-LiteSpeed-Cache-Control']);
        $this->assertSame(1, $fired);
    }

    private function staff(string $login, string $role, string $password): int
    {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@myaduan.my', 'display_name' => $login, 'role' => $role]);
        $this->assertIsInt($id);
        if ($role === 'myaduan_officer') {
            update_user_meta($id, \MyAduan\Access::POLICY_META, ['mode' => 'all', 'codes' => []]);
        }
        $this->created[$login] = $id;
        self::$users[$login] = $id;
        return $id;
    }
}
