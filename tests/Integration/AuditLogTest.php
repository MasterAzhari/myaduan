<?php
namespace MyAduan\Tests\Integration;

use MyAduan\AuditLog;

final class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AuditLog::reset();
        @unlink(AuditLog::file());
    }

    /** @return list<array> */
    private function lines(): array
    {
        $raw = is_file(AuditLog::file()) ? (string) file_get_contents(AuditLog::file()) : '';
        return array_map(static fn(string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", $raw))));
    }

    private function case(): array
    {
        [$s, $d] = $this->call('POST', '/complaints', [
            'client_key' => bin2hex(random_bytes(32)), 'name' => 'Ali Bin Abu', 'ic' => '880512105432', 'phone' => '0123456789',
            'details' => 'Lampu jalan rosak depan surau', 'location' => 'Jalan Melati 3', 'consent' => true,
        ]);
        $this->assertSame(201, $s, wp_json_encode($d));
        return $d;
    }

    public function test_complaint_and_case_actions_are_logged_without_personal_details(): void
    {
        $ref = $this->case()['reference'];
        AuditLog::reset();
        $this->call('POST', '/track', ['query' => '880512-10-5432']);
        $lines = $this->lines();
        $this->assertCount(2, $lines);
        $this->assertSame('ADUAN_BARU', $lines[0]['peristiwa']);
        $this->assertStringContainsString($ref, $lines[0]['mesej']);
        $this->assertSame(201, $lines[0]['http']);
        $this->assertSame('SEMAK_STATUS', $lines[1]['peristiwa']);
        $raw = (string) file_get_contents(AuditLog::file());
        foreach (['Ali Bin Abu', '880512', '0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertFileExists(AuditLog::dir() . '/.htaccess');
    }

    public function test_admin_actions_and_failures_are_logged(): void
    {
        global $wpdb;
        $this->case();
        $id = (int) $wpdb->get_var('SELECT id FROM ' . \MyAduan\Schema::table('cases'));
        @unlink(AuditLog::file());
        AuditLog::reset();
        $this->call('GET', "/admin/cases/$id", [], 'super');
        AuditLog::reset();
        [$s] = $this->call('POST', "/admin/cases/$id/operations", ['op' => 'classify', 'version' => 1, 'request_id' => bin2hex(random_bytes(32)), 'category_code' => 'A01'], 'super');
        $this->assertSame(200, $s);
        AuditLog::reset();
        [$s] = $this->call('POST', "/admin/cases/$id/operations", ['op' => 'classify', 'version' => 1, 'request_id' => bin2hex(random_bytes(32)), 'category_code' => 'A02'], 'super');
        $this->assertSame(409, $s);
        AuditLog::reset();
        $this->call('GET', '/admin/stats', [], 'super');

        $lines = $this->lines();
        $this->assertSame(['BUKA_KES', 'KES_CLASSIFY', 'TINDAKAN_KES'], array_column($lines, 'peristiwa'));
        $this->assertStringContainsString('t_super', $lines[1]['oleh']);
        $this->assertSame($id, $lines[1]['kes']);
        $this->assertSame('A01', $lines[1]['butiran']['category_code']);
        $this->assertSame('warning', $lines[2]['tahap']);
        $this->assertStringContainsString('gagal', $lines[2]['mesej']);
        $this->assertSame('myaduan_conflict', $lines[2]['butiran']['kod_ralat']);
    }

    public function test_unexpected_error_gives_user_a_code_that_matches_the_log(): void
    {
        $boom = static function (): void {
            throw new \LogicException('ujian rosak');
        };
        add_action('myaduan_case_created', $boom, 1);
        [$s, $d] = $this->call('POST', '/complaints', [
            'client_key' => bin2hex(random_bytes(32)), 'name' => 'Ali', 'ic' => '880512105432', 'phone' => '0123456789',
            'details' => 'Longkang tersumbat', 'location' => 'Jalan 1', 'consent' => true,
        ]);
        remove_action('myaduan_case_created', $boom, 1);
        $this->assertSame(500, $s);
        $code = $d['data']['trace'];
        $this->assertStringContainsString("Kod: $code", $d['message']);
        $line = $this->lines()[0];
        $this->assertSame($code, $line['kod']);
        $this->assertSame('error', $line['tahap']);
        $this->assertSame('ujian rosak', $line['butiran']['ralat']['mesej']);
    }

    public function test_password_is_never_logged(): void
    {
        $this->call('POST', '/admin/users', ['name' => 'Staf Baru', 'email' => 'staf.baru' . wp_rand() . '@myaduan.my', 'role' => 'officer', 'password' => 'Rahsia-Sangat-123'], 'super');
        $raw = (string) file_get_contents(AuditLog::file());
        $this->assertStringContainsString('PENGGUNA_BARU', $raw);
        $this->assertStringNotContainsString('Rahsia-Sangat-123', $raw);
    }

    public function test_log_can_be_read_back_with_filters(): void
    {
        $this->case();
        AuditLog::reset();
        $this->call('POST', '/track', ['query' => 'xx']);
        $all = \MyAduan\LogAbilities::read([]);
        $this->assertSame(2, $all['matched']);
        $this->assertSame('SEMAK_STATUS', $all['entries'][0]['peristiwa'], 'newest first');
        $this->assertSame(1, \MyAduan\LogAbilities::read(['peristiwa' => 'ADUAN_BARU'])['matched']);
        $this->assertSame(1, \MyAduan\LogAbilities::read(['level' => 'problems'])['matched']);
        $this->assertSame(1, \MyAduan\LogAbilities::read(['limit' => 1])['returned']);
        $files = \MyAduan\LogAbilities::files(3)['files'];
        $this->assertSame(2, $files[0]['baris']);
    }
}
