<?php
namespace MyAduan\Tests\Integration;

use MyAduan\Access;
use MyAduan\Schema;

/** Runs requests through the real REST server against a disposable database. */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static array $users = [];

    public static function setUpBeforeClass(): void
    {
        self::$users = [
            'super' => self::user('t_super', 'myaduan_superadmin', null),
            'a01' => self::user('t_officer_a01', 'myaduan_officer', ['mode' => 'selected', 'codes' => ['A01']]),
            'a02' => self::user('t_officer_a02', 'myaduan_officer', ['mode' => 'selected', 'codes' => ['A02']]),
            'all' => self::user('t_officer_all', 'myaduan_officer', ['mode' => 'all']),
            'viewer' => self::user('t_viewer', 'myaduan_viewer', null),
        ];
    }

    protected function setUp(): void
    {
        global $wpdb;
        foreach (['cases', 'events', 'submissions', 'access_log', 'attachments', 'agencies', 'notifications', 'push_subscriptions', 'mail_queue'] as $t) {
            $wpdb->query('TRUNCATE TABLE ' . Schema::table($t));
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%myaduan\\_rl\\_%'");
        wp_cache_flush();
        wp_set_current_user(0);
    }

    private static function user(string $login, string $role, ?array $policy): int
    {
        $existing = get_user_by('login', $login);
        $id = $existing ? $existing->ID : wp_insert_user([
            'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@myaduan.my',
            'display_name' => $login, 'role' => $role,
        ]);
        $user = new \WP_User($id);
        $user->set_role($role);
        $policy ? update_user_meta($id, Access::POLICY_META, $policy) : delete_user_meta($id, Access::POLICY_META);
        return (int) $id;
    }

    /** @return array{0:int, 1:array} status and decoded data */
    protected function call(string $method, string $path, array $body = [], ?string $as = null): array
    {
        wp_set_current_user($as ? self::$users[$as] : 0);
        $req = new \WP_REST_Request($method, '/myaduan/v1' . $path);
        if ($method === 'GET') {
            $req->set_query_params($body);
        } else {
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(wp_json_encode($body));
        }
        $res = rest_get_server()->dispatch($req);
        $data = rest_get_server()->response_to_data($res, false);
        return [$res->get_status(), json_decode(wp_json_encode($data), true)];
    }

    protected function complaint(array $over = []): array
    {
        return array_merge([
            'client_key' => bin2hex(random_bytes(32)),
            'name' => 'Siti binti Ahmad',
            'ic' => '880512-10-5432',
            'phone' => '012-987 6543',
            'details' => 'Lampu jalan di depan sekolah rosak sejak minggu lepas. Gelap waktu malam.',
            'location' => 'Jalan Sungai Tua, depan SK Sungai Tua',
            'consent' => true,
        ], $over);
    }

    protected function submit(array $over = []): array
    {
        [$status, $data] = $this->call('POST', '/complaints', $this->complaint($over));
        $this->assertSame(201, $status, wp_json_encode($data));
        return $data;
    }

    protected function case_id(string $reference): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('cases') . ' WHERE reference = %s', $reference));
    }

    protected function op(string $as, int $id, string $op, array $fields = [], ?int $version = null): array
    {
        if ($version === null) {
            [, $c] = $this->call('GET', "/admin/cases/$id", [], 'super');
            $version = $c['version'];
        }
        return $this->call('POST', "/admin/cases/$id/operations", array_merge([
            'op' => $op, 'version' => $version, 'request_id' => bin2hex(random_bytes(32)),
        ], $fields), $as);
    }
}
