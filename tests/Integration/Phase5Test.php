<?php
use MyAduan\Agencies;
use MyAduan\Mailer;
use MyAduan\Notifications;
use MyAduan\Portal;
use MyAduan\Schema;
use MyAduan\Storage;
use MyAduan\Tests\Integration\TestCase;
use MyAduan\WebPush;

final class Phase5Test extends TestCase
{
    private array $mails = [];
    private array $requests = [];
    private int $push_status = 201;

    protected function setUp(): void
    {
        parent::setUp();
        update_option('myaduan_settings', ['portal_path' => 'adminaduan']);
        add_filter('pre_wp_mail', [$this, 'capture_mail'], 10, 2);
        add_filter('pre_http_request', [$this, 'capture_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture_mail'], 10);
        remove_filter('pre_http_request', [$this, 'capture_http'], 10);
    }

    public function capture_mail($short, array $atts): bool
    {
        $this->mails[] = $atts;
        return true;
    }

    public function capture_http($pre, array $args, string $url): array
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        return ['headers' => [], 'body' => '', 'response' => ['code' => $this->push_status, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    private function agency_id(string $name): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Schema::table('agency_list') . ' WHERE name = %s', $name));
    }

    /** A case classified to A01 and assigned to officer a01, not yet accepted. */
    private function assigned_case(array $over = []): int
    {
        $ref = $this->submit($over)['reference'];
        $id = $this->case_id($ref);
        [$status] = $this->op('super', $id, 'assign', ['category_code' => 'A01', 'dun_slug' => 'sungai-tua', 'owner_id' => self::$users['a01']]);
        $this->assertSame(200, $status);
        return $id;
    }

    public function test_agency_list_is_seeded_and_managed_by_super_admin(): void
    {
        [$status, $list] = $this->call('GET', '/admin/agencies', [], 'super');
        $this->assertSame(200, $status);
        $this->assertGreaterThanOrEqual(40, count($list));
        $air = array_values(array_filter($list, static fn($a) => $a['name'] === 'Air Selangor - Wilayah Gombak'))[0];
        $this->assertSame(['miftahuddin.hayin@airselangor.com', 'zahlan.sulaiman@airselangor.com'], $air['cc']);
        $this->assertSame(403, $this->call('GET', '/admin/agencies', [], 'a01')[0]);

        [$status, $err] = $this->call('POST', '/admin/agencies', ['name' => 'Agensi Ujian', 'email' => 'bukan-emel'], 'super');
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('email', $err['data']['fields']);
        [$status, $new] = $this->call('POST', '/admin/agencies', ['name' => 'Agensi Ujian ' . uniqid(), 'email' => 'ujian@agensi.gov.my', 'cc' => 'a@agensi.gov.my; b@agensi.gov.my'], 'super');
        $this->assertSame(201, $status, wp_json_encode($new));
        $this->assertSame(['a@agensi.gov.my', 'b@agensi.gov.my'], $new['cc']);

        $catalog = fn() => array_column($this->call('GET', '/admin/catalog', [], 'a01')[1]['agencies'], 'id');
        $this->assertContains($new['id'], $catalog(), 'officers can pick active agencies');
        $this->call('POST', '/admin/agencies/' . $new['id'], array_merge($new, ['cc' => '', 'active' => false]), 'super');
        $this->assertNotContains($new['id'], $catalog(), 'inactive agencies are not offered');

        Agencies::seed();
        $this->assertSame(count($list) + 1, count($this->call('GET', '/admin/agencies', [], 'super')[1]), 'seeding again adds nothing');
    }

    public function test_refer_emails_each_agency_without_complainant_details(): void
    {
        global $wpdb;
        $id = $this->assigned_case(['email' => 'siti@contoh.com']);
        // A photo from the complainant, so the referral carries it.
        $rel = 'ujian/gambar-' . uniqid() . '.jpg';
        wp_mkdir_p(Storage::root() . '/ujian');
        file_put_contents(Storage::root() . '/' . $rel, 'jpeg');
        $wpdb->insert(Schema::table('attachments'), ['case_id' => $id, 'kind' => 'public', 'path' => $rel, 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('a', 64), 'created_at' => gmdate('Y-m-d H:i:s')]);

        $mps = $this->agency_id('Majlis Perbandaran Selayang (MPS)');
        $air = $this->agency_id('Air Selangor - Wilayah Gombak');
        [$status, $c] = $this->op('a01', $id, 'refer', ['agency_ids' => [$mps, $air], 'note' => 'Mohon ganti lampu jalan yang rosak.', 'followup_at' => '2026-11-01T09:00']);
        $this->assertSame(200, $status, wp_json_encode($c));
        $this->assertSame(['DALAM TINDAKAN', 'AGENSI'], [$c['status'], $c['track']]);
        $this->assertNotNull($c['accepted_at'], 'the owner deciding also accepts the case');
        $this->assertCount(2, $c['referrals']);
        $this->assertSame('REFER', $c['events'][0]['event']);

        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::table('mail_queue') . " WHERE dedupe_key LIKE 'ref:%' ORDER BY recipient", ARRAY_A);
        $this->assertSame(['junaidah.jantan@airselangor.gov.my', 'ydp@mps.gov.my'], array_column($rows, 'recipient'));
        foreach ($rows as $row) {
            $this->assertStringContainsString($c['reference'], $row['body']);
            $this->assertStringContainsString('Mohon ganti lampu jalan', $row['body']);
            $this->assertStringContainsString('Lampu jalan di depan sekolah', $row['body']);
            foreach (['Siti', '880512', '5432', '0129876543', '60129876543', 'siti@contoh.com'] as $secret) {
                $this->assertStringNotContainsString($secret, $row['body'] . $row['subject'], 'no complainant details go to agencies');
            }
        }
        $this->assertContains('Cc: miftahuddin.hayin@airselangor.com, zahlan.sulaiman@airselangor.com', json_decode($rows[0]['headers'], true));

        Mailer::process();
        $sent = array_values(array_filter($this->mails, static fn($m) => $m['to'] === 'ydp@mps.gov.my'))[0];
        $this->assertContains('Reply-To: info@myaduan.my', $sent['headers']);
        $this->assertCount(1, $sent['attachments'], 'photo attached');
        $this->assertStringEndsWith('.jpg', $sent['attachments'][0]);

        // The officer records what the agency said.
        $ref_id = $c['referrals'][0]['id'];
        [$status, $c] = $this->op('a01', $id, 'agency_update', ['referral_id' => $ref_id, 'agency_status' => 'Dalam tindakan agensi', 'reference_no' => 'MPS/2026/123']);
        $this->assertSame(200, $status, wp_json_encode($c));
        $updated = array_values(array_filter($c['referrals'], static fn($r) => $r['id'] === $ref_id))[0];
        $this->assertSame(['Dalam tindakan agensi', 'MPS/2026/123'], [$updated['status'], $updated['reference_no']]);
        $this->assertSame(['Dirujuk', 'Dalam tindakan agensi'], $c['events'][0]['changes']['agency_status']);
    }

    public function test_decision_rules(): void
    {
        $id = $this->assigned_case();
        $mps = $this->agency_id('Majlis Perbandaran Selayang (MPS)');
        $this->assertSame(404, $this->op('a02', $id, 'refer', ['agency_ids' => [$mps], 'note' => 'Bukan kes saya ini.'])[0], 'other officers cannot see it');
        $this->assertSame(403, $this->op('viewer', $id, 'self')[0]);
        $this->assertSame(422, $this->op('a01', $id, 'refer', ['agency_ids' => [], 'note' => 'Tiada agensi dipilih.'])[0]);
        $this->assertSame(422, $this->op('a01', $id, 'refer', ['agency_ids' => [$mps], 'note' => 'pendek'])[0]);
        $this->assertSame(422, $this->op('a01', $id, 'need_info', ['note' => 'pendek'])[0]);
        $this->assertSame(422, $this->op('a01', $id, 'agency_update', ['referral_id' => 1, 'agency_status' => 'Dirujuk'])[0], 'nothing referred yet');

        [$status, $c] = $this->op('a01', $id, 'need_info', ['note' => 'Perlu lokasi tepat dan gambar terkini.']);
        $this->assertSame([200, 'MAKLUMAT'], [$status, $c['track']]);
        [, $list] = $this->call('GET', '/admin/cases', ['flag' => 'need_info'], 'super');
        $this->assertSame([$id], array_column($list['items'], 'id'));
        [$status, $c] = $this->op('a01', $id, 'self');
        $this->assertSame([200, 'SENDIRI'], [$status, $c['track']]);
        $this->assertContains('request_close', $c['allowed_operations'], 'closing stays available');

        // A Super Admin deciding on an unassigned case takes it on.
        $other = $this->case_id($this->submit()['reference']);
        [$status, $c] = $this->op('super', $other, 'refer', ['agency_ids' => [$mps], 'note' => 'Terus panjangkan kepada MPS.']);
        $this->assertSame(200, $status, wp_json_encode($c));
        $this->assertSame([self::$users['super'], 'DALAM TINDAKAN'], [$c['owner_id'], $c['status']]);
        $this->assertNotNull($c['accepted_at']);

        $inactive = (new Agencies())->save(0, ['name' => 'Tutup ' . uniqid(), 'email' => 'tutup@agensi.gov.my', 'active' => false]);
        $this->assertSame(422, $this->op('super', $other, 'refer', ['agency_ids' => [$inactive['id']], 'note' => 'Agensi tidak aktif lagi.'])[0]);
    }

    public function test_staff_are_notified_in_the_portal_not_by_email(): void
    {
        global $wpdb;
        $id = $this->assigned_case();
        $n = Schema::table('notifications');
        $kinds = static fn(int $uid): array => $wpdb->get_col($wpdb->prepare("SELECT kind FROM $n WHERE user_id = %d ORDER BY id", $uid));
        $this->assertContains('BARU', $kinds(self::$users['super']));
        $this->assertSame(['TUGASAN'], $kinds(self::$users['a01']));
        $this->assertSame([], $kinds(self::$users['a02']));
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('mail_queue') . " WHERE dedupe_key NOT LIKE 'ack:%'"), 'staff e-mail is off by default');

        [$status, $data] = $this->call('GET', '/admin/notifications', [], 'a01');
        $this->assertSame([200, 1], [$status, $data['unread']]);
        $this->assertSame($id, $data['items'][0]['case_id']);
        $this->assertStringNotContainsString('Siti', wp_json_encode($data));
        $this->call('GET', "/admin/cases/$id", [], 'a01');
        $this->assertSame(0, $this->call('GET', '/admin/notifications', ['count' => 1], 'a01')[1]['unread'], 'opening the case reads its notifications');

        $this->op('a01', $id, 'accept');
        $this->op('a01', $id, 'request_close', ['closure_reason' => 'Selesai', 'resolution' => 'Lampu telah diganti oleh MPS.', 'public_draft' => 'Lampu jalan telah dibaiki.']);
        $this->assertContains('MOHON_TUTUP', $kinds(self::$users['super']));
        [, $after] = $this->call('POST', '/admin/notifications/read', [], 'super');
        $this->assertSame(0, $after['unread']);
    }

    public function test_push_is_encrypted_signed_and_sent_to_subscribed_devices(): void
    {
        global $wpdb;
        $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($ua)['ec'];
        $ua_public = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $keys = ['p256dh' => WebPush::b64u($ua_public), 'auth' => WebPush::b64u($auth)];

        $this->assertSame(422, $this->call('POST', '/admin/push', ['endpoint' => 'https://evil.example/hook', 'keys' => $keys], 'a01')[0], 'only real push services');
        $bad = ['p256dh' => WebPush::b64u("\x04" . str_repeat("\0", 64)), 'auth' => $keys['auth']];
        $this->assertSame(422, $this->call('POST', '/admin/push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/bad', 'keys' => $bad], 'a01')[0], 'not a point on the curve');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        [$status, $r] = $this->call('POST', '/admin/push', ['endpoint' => $endpoint, 'keys' => $keys], 'a01');
        $this->assertSame([201, 1], [$status, $r['devices']]);
        [, $info] = $this->call('GET', '/admin/push', [], 'a01');
        $this->assertSame(65, strlen(WebPush::b64u_decode($info['public_key'])));

        $id = $this->assigned_case();
        $this->assertSame('pending', $wpdb->get_var($wpdb->prepare('SELECT push_state FROM ' . Schema::table('notifications') . ' WHERE user_id = %d', self::$users['a01'])));
        $this->requests = [];
        $this->assertSame(1, Notifications::send_pushes());
        $this->assertCount(1, $this->requests);
        [$url, $args] = [$this->requests[0]['url'], $this->requests[0]['args']];
        $this->assertSame($endpoint, $url);
        $this->assertSame('aes128gcm', $args['headers']['Content-Encoding']);

        // VAPID: the JWT is for the push service and verifies with the advertised key.
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+\.[\w-]+\.[\w-]+), k=([\w-]+)$/', $args['headers']['Authorization']);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $args['headers']['Authorization'], $m);
        $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::b64u_decode($m[2]), true)['aud']);
        $this->assertSame($info['public_key'], $m[4]);
        $raw = WebPush::b64u_decode($m[3]);
        $der_int = static function (string $i): string {
            $i = ltrim($i, "\0");
            if (ord($i[0]) & 0x80) {
                $i = "\0" . $i;
            }
            return "\x02" . chr(strlen($i)) . $i;
        };
        $seq = $der_int(substr($raw, 0, 32)) . $der_int(substr($raw, 32));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . WebPush::b64u_decode($m[4])), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify("$m[1].$m[2]", "\x30" . chr(strlen($seq)) . $seq, $pem, OPENSSL_ALGO_SHA256));

        // The device can decrypt the payload (RFC 8291, receiver side).
        $body = $args['body'];
        $salt = substr($body, 0, 16);
        $as_public = substr($body, 21, 65);
        $as_pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $as_public), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($as_pem), $ua, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = substr($body, 86);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain);
        $payload = json_decode(substr($plain, 0, -1), true);
        $this->assertStringContainsString('ditugaskan kepada anda', $payload['title']);
        $this->assertStringEndsWith("#/kes/$id", $payload['url']);

        // A subscription saved by another user's browser moves to whoever registers it now.
        $this->call('POST', '/admin/push', ['endpoint' => $endpoint, 'keys' => $keys], 'a02');
        $this->assertSame([0, 1], [$this->call('GET', '/admin/push', [], 'a01')[1]['devices'], $this->call('GET', '/admin/push', [], 'a02')[1]['devices']]);
        $this->call('POST', '/admin/push', ['endpoint' => $endpoint, 'keys' => $keys], 'a01');

        // A broken stored key does not stop delivery to the user's other devices.
        $wpdb->insert(Schema::table('push_subscriptions'), ['user_id' => self::$users['a01'], 'endpoint_hash' => hash('sha256', 'x'), 'endpoint' => 'https://fcm.googleapis.com/fcm/send/broken',
            'p256dh' => $bad['p256dh'], 'auth' => $keys['auth'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->requests = [];
        Notifications::add([self::$users['a01']], $id, 'UJIAN', 'Ujian rosak');
        $this->assertSame(1, Notifications::send_pushes());
        $this->assertSame([$endpoint], array_column($this->requests, 'url'));
        $this->assertSame(1, $this->call('GET', '/admin/push', [], 'a01')[1]['devices'], 'the broken subscription is dropped');

        // A device that has gone away is forgotten.
        $this->push_status = 410;
        Notifications::add([self::$users['a01']], $id, 'UJIAN', 'Ujian');
        Notifications::send_pushes();
        $this->assertSame(0, $this->call('GET', '/admin/push', [], 'a01')[1]['devices']);
    }

    public function test_portal_is_installable(): void
    {
        $manifest = json_decode(Portal::manifest()['body'], true);
        $this->assertSame(['standalone', home_url('/adminaduan/'), home_url('/adminaduan/')], [$manifest['display'], $manifest['start_url'], $manifest['scope']]);
        $this->assertSame(['192x192', '512x512'], array_column($manifest['icons'], 'sizes'));
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(MYADUAN_DIR . '/assets/img/' . basename($icon['src']));
        }
        $sw = Portal::service_worker();
        $this->assertStringStartsWith('application/javascript', $sw['type']);
        $this->assertStringContainsString("addEventListener('push'", $sw['body']);
        $this->assertStringNotContainsString('__ICON__', $sw['body']);
        $page = Portal::response(new WP_User(self::$users['a01']))['body'];
        $this->assertStringContainsString('manifest.webmanifest', $page);
        $this->assertStringContainsString('apple-touch-icon', $page);
    }
}
