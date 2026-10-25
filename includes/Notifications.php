<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staff notifications inside the portal (the bell), pushed to each device a
 * user turned them on for. They hold the reference and title only, never the
 * complainant's details.
 *
 * Pushes go out right after the response that caused them, and WP-Cron sends
 * any left behind.
 */
final class Notifications
{
    private const PUSH_BATCH = 30;
    private static bool $flush = false;

    public static function register(): void
    {
        add_action('myaduan_case_created', [self::class, 'on_created'], 20, 2);
        add_action('myaduan_case_operated', [self::class, 'on_operated'], 20, 6);
        add_action(Mailer::HOOK, [self::class, 'send_pushes']);
        add_action('shutdown', [self::class, 'flush'], 100);
    }

    public static function on_created(int $id, string $reference): void
    {
        $case = self::summary($id);
        self::add(self::managers(), $id, 'BARU', "Aduan baru $reference", $case['title'] ?? '');
    }

    public static function on_operated(int $id, string $op, array $actor, string $from, string $to, array $changes): void
    {
        $case = self::summary($id);
        if (!$case) {
            return;
        }
        $ref = $case['reference'];
        $owner = (int) $case['owner_id'];
        $not_actor = static fn(array $ids): array => array_values(array_diff($ids, [(int) $actor['id']]));
        if (isset($changes['owner_id']) && $op === 'assign') {
            self::add($not_actor([(int) $changes['owner_id'][1]]), $id, 'TUGASAN', "Kes $ref ditugaskan kepada anda", $case['title']);
        }
        match ($op) {
            'request_close' => self::add($not_actor(self::managers()), $id, 'MOHON_TUTUP', "Kes $ref menunggu pengesahan penutupan", $actor['name'] . ' memohon kes ini ditutup.'),
            'return' => self::add($not_actor([$owner]), $id, 'DIKEMBALIKAN', "Kes $ref dikembalikan untuk tindakan", 'Lihat catatan Super Admin dalam kes.'),
            'reopen' => self::add($not_actor([$owner]), $id, 'DIBUKA_SEMULA', "Kes $ref dibuka semula", $case['title']),
            'approve_close' => self::add($not_actor([$owner]), $id, 'DITUTUP', "Kes $ref telah disahkan & ditutup", $case['title']),
            'comment' => self::add($not_actor([$owner]), $id, 'CATATAN', "Catatan baru pada kes $ref", $actor['name'] . ' menambah catatan.'),
            'refer' => self::add($not_actor(self::managers()), $id, 'DIPANJANGKAN', "Kes $ref dipanjangkan ke agensi", (string) ($changes['agencies'][1] ?? '')),
            default => null,
        };
    }

    /** @param list<int> $user_ids */
    public static function add(array $user_ids, ?int $case_id, string $kind, string $title, string $body = ''): void
    {
        global $wpdb;
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (!$user_ids) {
            return;
        }
        $subscribed = array_map('intval', $wpdb->get_col(
            'SELECT DISTINCT user_id FROM ' . Schema::table('push_subscriptions') . ' WHERE user_id IN (' . implode(',', $user_ids) . ')'
        ));
        $now = Cases::now();
        foreach ($user_ids as $uid) {
            $push = in_array($uid, $subscribed, true);
            $wpdb->insert(Schema::table('notifications'), [
                'user_id' => $uid, 'case_id' => $case_id, 'kind' => $kind,
                'title' => mb_substr($title, 0, 200), 'body' => mb_substr($body, 0, 500),
                'push_state' => $push ? 'pending' : 'none', 'created_at' => $now,
            ]);
            self::$flush = self::$flush || $push;
        }
    }

    /** @return array{items:list<array>, unread:int} */
    public static function list(int $user_id, bool $unread_only = false, int $limit = 50): array
    {
        global $wpdb;
        $n = Schema::table('notifications');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.case_id, n.kind, n.title, n.body, n.read_at, n.created_at FROM $n n WHERE n.user_id = %d" . ($unread_only ? ' AND n.read_at IS NULL' : '') . ' ORDER BY n.id DESC LIMIT %d',
            $user_id, max(1, min(100, $limit))
        ), ARRAY_A);
        return [
            'items' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'],
                'case_id' => $r['case_id'] ? (int) $r['case_id'] : null,
                'kind' => $r['kind'],
                'title' => $r['title'],
                'body' => $r['body'],
                'read' => $r['read_at'] !== null,
                'created_at' => Cases::iso($r['created_at']),
            ], $rows),
            'unread' => self::unread($user_id),
        ];
    }

    public static function unread(int $user_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('notifications') . ' WHERE user_id = %d AND read_at IS NULL', $user_id));
    }

    /** Marks the given ids, a case's notifications, or (both null) everything as read. */
    public static function mark_read(int $user_id, ?array $ids = null, ?int $case_id = null): int
    {
        global $wpdb;
        $where = $wpdb->prepare('user_id = %d AND read_at IS NULL', $user_id);
        if ($ids !== null) {
            $ids = array_filter(array_map('intval', $ids));
            if (!$ids) {
                return self::unread($user_id);
            }
            $where .= ' AND id IN (' . implode(',', $ids) . ')';
        } elseif ($case_id !== null) {
            $where .= $wpdb->prepare(' AND case_id = %d', $case_id);
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('notifications') . " SET read_at = %s WHERE $where", Cases::now()));
        return self::unread($user_id);
    }

    /** Saves a device's push subscription for the user. */
    public static function subscribe(int $user_id, string $endpoint, string $p256dh, string $auth, string $device): void
    {
        global $wpdb;
        if (!WebPush::allowed_endpoint($endpoint)) {
            throw new WorkflowException('Perkhidmatan push peranti ini tidak disokong.');
        }
        if (strlen(WebPush::b64u_decode($p256dh)) !== 65 || strlen(WebPush::b64u_decode($auth)) !== 16) {
            throw new WorkflowException('Kunci langganan push tidak sah.');
        }
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Schema::table('push_subscriptions') . ' (user_id, endpoint_hash, endpoint, p256dh, auth, device, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), device = VALUES(device)',
            $user_id, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($device, 0, 190), Cases::now()
        ));
    }

    public static function unsubscribe(int $user_id, string $endpoint): void
    {
        global $wpdb;
        $wpdb->delete(Schema::table('push_subscriptions'), ['user_id' => $user_id, 'endpoint_hash' => hash('sha256', $endpoint)]);
    }

    public static function devices(int $user_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('push_subscriptions') . ' WHERE user_id = %d', $user_id));
    }

    /** Sends pushes after the response has gone back to the browser. */
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
        self::send_pushes();
    }

    /** Sends pending pushes. Rows are claimed first, so overlapping runs do not double-send. */
    public static function send_pushes(): int
    {
        global $wpdb;
        $n = Schema::table('notifications');
        $s = Schema::table('push_subscriptions');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $n WHERE push_state = 'pending' ORDER BY id LIMIT %d", self::PUSH_BATCH), ARRAY_A);
        $sent = 0;
        foreach ($rows as $row) {
            if ($wpdb->query($wpdb->prepare("UPDATE $n SET push_state = 'sending' WHERE id = %d AND push_state = 'pending'", $row['id'])) !== 1) {
                continue;
            }
            $payload = wp_json_encode([
                'title' => $row['title'],
                'body' => $row['body'],
                'url' => Settings::portal_url($row['case_id'] ? '/kes/' . $row['case_id'] : '/notifikasi'),
                'tag' => $row['case_id'] ? 'kes-' . $row['case_id'] : 'myaduan-' . $row['id'],
            ]);
            $ok = false;
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $s WHERE user_id = %d", $row['user_id']), ARRAY_A) as $sub) {
                $status = WebPush::send($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);
                if ($status >= 200 && $status < 300) {
                    $ok = true;
                    $wpdb->update($s, ['last_ok_at' => Cases::now()], ['id' => $sub['id']]);
                } elseif ($status === 404 || $status === 410) {
                    // The browser dropped this subscription.
                    $wpdb->delete($s, ['id' => $sub['id']]);
                }
            }
            $wpdb->update($n, ['push_state' => $ok ? 'sent' : 'failed'], ['id' => $row['id']]);
            $sent += $ok ? 1 : 0;
        }
        return $sent;
    }

    /** @return list<int> active Super Admins and site administrators */
    private static function managers(): array
    {
        return array_map('intval', get_users(['capability' => Access::CAP_MANAGE, 'fields' => 'ID']));
    }

    private static function summary(int $id): array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id, reference, title, owner_id FROM ' . Schema::table('cases') . ' WHERE id = %d', $id), ARRAY_A) ?: [];
    }
}
