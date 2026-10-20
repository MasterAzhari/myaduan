<?php
use MyAduan\Mailer;
use MyAduan\Schema;
use MyAduan\Storage;
use MyAduan\Tests\Integration\TestCase;

final class Phase2Test extends TestCase
{
    private array $tmp = [];
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Schema::table('mail_queue'));
        add_filter('myaduan_stream_files', '__return_false');
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
        update_option('myaduan_settings', ['notify_emails' => 'urusetia@myaduan.my']);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    public function capture($short, array $atts): bool
    {
        $this->mails[] = $atts;
        return true;
    }

    /** A JPEG in PHP's temp dir, the way an upload arrives. */
    private function jpeg(int $w = 3000, int $h = 1500): array
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 30, 40));
        $path = tempnam(sys_get_temp_dir(), 'php');
        imagejpeg($img, $path, 90);
        // Append junk after the image end marker; re-encoding must drop it.
        file_put_contents($path, 'HIDDEN-PAYLOAD-GPS', FILE_APPEND);
        $this->tmp[] = $path;
        return ['tmp_name' => $path, 'name' => 'foto.jpg', 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    private function submit_with_files(array $files, array $over = []): array
    {
        wp_set_current_user(0);
        $req = new WP_REST_Request('POST', '/myaduan/v1/complaints');
        $req->set_body_params(array_merge($this->complaint(), ['consent' => 'true'], $over));
        $req->set_file_params(['images' => [
            'tmp_name' => array_column($files, 'tmp_name'), 'name' => array_column($files, 'name'),
            'type' => array_column($files, 'type'), 'error' => array_column($files, 'error'), 'size' => array_column($files, 'size'),
        ]]);
        $res = rest_get_server()->dispatch($req);
        return [$res->get_status(), json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true)];
    }

    public function test_images_are_resized_cleaned_and_private(): void
    {
        global $wpdb;
        [$status, $data] = $this->submit_with_files([$this->jpeg(), $this->jpeg(800, 600)]);
        $this->assertSame(201, $status, wp_json_encode($data));

        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::table('attachments') . ' ORDER BY id', ARRAY_A);
        $this->assertCount(2, $rows);
        $abs = Storage::root() . '/' . $rows[0]['path'];
        $this->assertFileExists($abs);
        $this->assertStringNotContainsString('public_html', realpath($abs), 'stored outside the web root');
        [$w, $h] = getimagesize($abs);
        $this->assertSame([2000, 1000], [$w, $h], 'long side capped at 2000px');
        $this->assertStringNotContainsString('HIDDEN-PAYLOAD', file_get_contents($abs));
        $this->assertSame($rows[0]['sha256'], hash_file('sha256', $abs));

        $id = (int) $rows[0]['case_id'];
        [$s, $file] = $this->call('GET', '/admin/files/' . $rows[0]['id'], [], 'super');
        $this->assertSame(200, $s);
        $this->assertSame('image/jpeg', $file['mime']);
        $this->assertSame(404, $this->call('GET', '/admin/files/' . $rows[0]['id'], [], 'a01')[0], 'unclassified case files are Super Admin only');
        $this->assertSame(401, $this->call('GET', '/admin/files/' . $rows[0]['id'])[0]);

        [, $detail] = $this->call('GET', "/admin/cases/$id", [], 'super');
        $this->assertCount(2, $detail['attachments']);
    }

    public function test_too_many_or_bad_images_rejected_before_saving(): void
    {
        global $wpdb;
        [$status, $data] = $this->submit_with_files([$this->jpeg(10, 10), $this->jpeg(10, 10), $this->jpeg(10, 10), $this->jpeg(10, 10)]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('images', $data['data']['fields']);

        $fake = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($fake, '<?php echo "not an image";');
        $this->tmp[] = $fake;
        [$status, $data] = $this->submit_with_files([['tmp_name' => $fake, 'name' => 'x.jpg', 'type' => 'image/jpeg', 'error' => 0, 'size' => 30]]);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('JPG, PNG atau WebP', $data['data']['fields']['images']);

        [$status] = $this->submit_with_files([['tmp_name' => '/etc/passwd', 'name' => 'p.jpg', 'type' => 'image/jpeg', 'error' => 0, 'size' => 10]]);
        $this->assertSame(422, $status, 'paths outside the upload temp dir are refused');
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('cases')));
    }

    public function test_mail_queue_new_case_and_assignment(): void
    {
        global $wpdb;
        $ref = $this->submit()['reference'];
        $id = $this->case_id($ref);
        $this->op('super', $id, 'assign', ['owner_id' => self::$users['a01'], 'category_code' => 'A01']);

        $queued = $wpdb->get_results('SELECT recipient, subject, body FROM ' . Schema::table('mail_queue') . ' ORDER BY id', ARRAY_A);
        $this->assertSame(['urusetia@myaduan.my', 't_officer_a01@myaduan.my'], array_column($queued, 'recipient'));
        $this->assertStringContainsString($ref, $queued[0]['subject']);
        foreach ($queued as $m) {
            $this->assertStringNotContainsString('880512', $m['body'], 'no IC in email');
            $this->assertStringNotContainsString('Siti', $m['body'], 'no complainant name in email');
        }

        $this->assertSame(2, Mailer::process());
        $this->assertCount(2, $this->mails);
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('mail_queue') . " WHERE state <> 'sent'"));
        $this->assertSame(0, Mailer::process(), 'nothing sent twice');
    }

    public function test_failed_mail_retries_then_gives_up(): void
    {
        global $wpdb;
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        add_filter('pre_wp_mail', '__return_false');
        Mailer::queue('t1', 'x@myaduan.my', 'S', 'B');
        for ($i = 0; $i < 5; $i++) {
            Mailer::process();
        }
        remove_filter('pre_wp_mail', '__return_false');
        $row = $wpdb->get_row('SELECT state, attempts FROM ' . Schema::table('mail_queue'), ARRAY_A);
        $this->assertSame(['state' => 'failed', 'attempts' => '3'], $row);
    }

    public function test_shortcodes_render_mount_points(): void
    {
        update_option('myaduan_settings', ['privacy_url' => 'https://myaduan.my/privasi/']);
        $html = do_shortcode('[myaduan_borang]');
        $this->assertStringContainsString('<myaduan-borang', $html);
        $this->assertStringContainsString('data-api="' . esc_attr(rest_url('myaduan/v1')) . '"', $html);
        $this->assertStringContainsString('https://myaduan.my/privasi/', $html);
        $this->assertStringContainsString('<myaduan-semak', do_shortcode('[myaduan_semak]'));
        $this->assertTrue(wp_script_is('myaduan-public', 'enqueued'));
    }

    public function test_rate_limit_applies_before_images_are_decoded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->submit();
        }
        $fake = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($fake, 'not an image');
        $this->tmp[] = $fake;
        // A 422 here would mean the file was decoded although the limit was already reached.
        [$status] = $this->submit_with_files([['tmp_name' => $fake, 'name' => 'x.jpg', 'type' => 'image/jpeg', 'error' => 0, 'size' => 12]]);
        $this->assertSame(429, $status);
    }

    public function test_timed_out_last_attempt_becomes_failed(): void
    {
        global $wpdb;
        Mailer::queue('t2', 'y@myaduan.my', 'S', 'B');
        $wpdb->query('UPDATE ' . Schema::table('mail_queue') . " SET state = 'sending', attempts = 3, updated_at = '2020-01-01 00:00:00'");
        Mailer::process();
        $this->assertSame('failed', $wpdb->get_var('SELECT state FROM ' . Schema::table('mail_queue')));
    }
}
