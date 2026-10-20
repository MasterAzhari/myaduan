<?php
use MyAduan\Schema;
use MyAduan\Tests\Integration\TestCase;

final class ApiTest extends TestCase
{
    public function test_submit_stores_encrypted_and_is_idempotent(): void
    {
        global $wpdb;
        $body = $this->complaint();
        [$s1, $first] = $this->call('POST', '/complaints', $body);
        $this->assertSame(201, $s1);
        $this->assertMatchesRegularExpression('/^GOM-\d{8}-[A-F0-9]{10}$/', $first['reference']);
        $this->assertFalse($first['duplicate']);

        $row = $wpdb->get_row('SELECT * FROM ' . Schema::table('cases'), ARRAY_A);
        $this->assertStringNotContainsString('880512', $row['ic_enc'] . $row['name_enc'] . $row['phone_enc']);
        $this->assertStringNotContainsString('Siti', $row['name_enc']);
        $this->assertSame('DITERIMA', $row['status']);
        $this->assertNull($row['category_code'], 'unclassified until Super Admin triage');

        [, $again] = $this->call('POST', '/complaints', $body);
        $this->assertSame($first['reference'], $again['reference']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('cases')));

        [$s3, $err] = $this->call('POST', '/complaints', array_merge($body, ['details' => $body['details'] . ' Tambahan.']));
        $this->assertSame(409, $s3);
        $this->assertSame('myaduan_duplicate_key', $err['code']);
    }

    public function test_validation_errors_per_field(): void
    {
        [$status, $data] = $this->call('POST', '/complaints', $this->complaint(['ic' => '123', 'consent' => false]));
        $this->assertSame(422, $status);
        $this->assertSame(['ic', 'consent'], array_keys($data['data']['fields']));
    }

    public function test_honeypot_rejects(): void
    {
        [$status] = $this->call('POST', '/complaints', $this->complaint(['website' => 'http://spam']));
        $this->assertSame(400, $status);
    }

    public function test_rate_limit_allows_exact_retry(): void
    {
        $bodies = [];
        for ($i = 0; $i < 5; $i++) {
            $bodies[] = $b = $this->complaint();
            $this->assertSame(201, $this->call('POST', '/complaints', $b)[0]);
        }
        [$status] = $this->call('POST', '/complaints', $this->complaint());
        $this->assertSame(429, $status);
        [$retry, $data] = $this->call('POST', '/complaints', $bodies[2]);
        $this->assertSame(201, $retry);
        $this->assertTrue($data['duplicate']);
    }

    public function test_track_returns_public_fields_only(): void
    {
        $ref = $this->submit()['reference'];
        $this->submit(['ic' => '880512105432', 'details' => 'Longkang tersumbat dan berbau busuk di belakang rumah.']);

        [$status, $byIc] = $this->call('POST', '/track', ['query' => '880512-10-5432']);
        $this->assertSame(200, $status);
        $this->assertCount(2, $byIc['items']);
        $this->assertSame(['reference', 'status', 'public_message', 'created_at', 'updated_at'], array_keys($byIc['items'][0]));

        [, $byRef] = $this->call('POST', '/track', ['query' => strtolower($ref)]);
        $this->assertCount(1, $byRef['items']);

        [$bad] = $this->call('POST', '/track', ['query' => 'abc']);
        $this->assertSame(400, $bad);
    }

    public function test_admin_requires_login_and_role(): void
    {
        $this->assertSame(401, $this->call('GET', '/admin/cases')[0]);
        $this->assertSame(403, $this->call('GET', '/admin/cases', [], 'viewer')[0], 'viewer sees reports, not cases');
        $this->assertSame(200, $this->call('GET', '/admin/stats', [], 'viewer')[0]);
    }

    public function test_unclassified_cases_are_super_admin_only(): void
    {
        $id = $this->case_id($this->submit()['reference']);
        [, $super] = $this->call('GET', '/admin/cases', [], 'super');
        $this->assertSame(1, $super['total']);
        $this->assertSame('Siti binti Ahmad', $super['items'][0]['complainant']);

        [, $officer] = $this->call('GET', '/admin/cases', [], 'a01');
        $this->assertSame(0, $officer['total']);
        $this->assertSame(404, $this->call('GET', "/admin/cases/$id", [], 'a01')[0]);
    }

    public function test_full_workflow_through_api(): void
    {
        global $wpdb;
        $ref = $this->submit()['reference'];
        $id = $this->case_id($ref);

        // Super Admin classifies and assigns in one step.
        [$s, $c] = $this->op('super', $id, 'assign', ['owner_id' => self::$users['a01'], 'category_code' => 'A04', 'dun_slug' => 'sungai-tua', 'priority' => 'SEGERA']);
        $this->assertSame(422, $s, 'A01 officer cannot own an A04 case');
        $this->assertStringContainsString('tiada akses', $c['message']);

        [$s, $c] = $this->op('super', $id, 'assign', ['owner_id' => self::$users['a01'], 'category_code' => 'A01', 'dun_slug' => 'sungai-tua', 'priority' => 'SEGERA']);
        $this->assertSame(200, $s, wp_json_encode($c));
        $this->assertSame('DALAM SEMAKAN', $c['status']);
        $this->assertSame(2, $c['version']);

        $this->assertSame(1, $this->call('GET', '/admin/cases', [], 'a01')[1]['total']);
        $this->assertSame(404, $this->call('GET', "/admin/cases/$id", [], 'a02')[0], 'other category stays hidden');

        [, $detail] = $this->call('GET', "/admin/cases/$id", [], 'a01');
        $this->assertSame('880512105432', $detail['complainant']['ic']);
        $this->assertContains('accept', $detail['allowed_operations']);
        $this->assertNotContains('assign', $detail['allowed_operations']);

        $this->assertSame(200, $this->op('a01', $id, 'accept')[0]);
        $this->assertSame(200, $this->op('a01', $id, 'action', ['note' => 'Laporan dihantar ke MPS', 'followup_at' => '2026-10-28T10:00'])[0]);
        [$s, $c] = $this->op('a01', $id, 'request_close', [
            'closure_reason' => 'Selesai', 'resolution' => 'MPS telah membaiki lampu jalan', 'public_draft' => 'Lampu jalan telah dibaiki. Terima kasih.',
        ]);
        $this->assertSame('MENUNGGU PENGESAHAN', $c['status']);
        $this->assertSame(200, $s);

        $this->assertSame(422, $this->op('a01', $id, 'approve_close')[0], 'officer cannot close');
        [$s, $c] = $this->op('super', $id, 'approve_close');
        $this->assertSame('DITUTUP', $c['status']);
        $this->assertNull($c['followup_at']);

        [, $track] = $this->call('POST', '/track', ['query' => $ref]);
        $this->assertSame('DITUTUP', $track['items'][0]['status']);
        $this->assertSame('Lampu jalan telah dibaiki. Terima kasih.', $track['items'][0]['public_message']);

        $events = $wpdb->get_col($wpdb->prepare('SELECT event FROM ' . Schema::table('events') . ' WHERE case_id = %d ORDER BY id', $id));
        $this->assertSame(['CIPTA', 'ASSIGN', 'ACCEPT', 'ACTION', 'REQUEST_CLOSE', 'APPROVE_CLOSE'], $events);
        $this->assertGreaterThan(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('access_log')));
    }

    public function test_version_conflict_and_idempotent_retry(): void
    {
        global $wpdb;
        $id = $this->case_id($this->submit()['reference']);
        $body = ['op' => 'classify', 'version' => 1, 'request_id' => str_repeat('a', 64), 'category_code' => 'A02'];
        [$s1, $c1] = $this->call('POST', "/admin/cases/$id/operations", $body, 'super');
        $this->assertSame(200, $s1);
        [$s2, $c2] = $this->call('POST', "/admin/cases/$id/operations", $body, 'super');
        $this->assertSame(200, $s2, 'same request id is a safe retry');
        $this->assertSame($c1['version'], $c2['version']);
        $this->assertSame('1', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('events') . " WHERE case_id = %d AND event = 'CLASSIFY'", $id)));

        [$s3, $err] = $this->call('POST', "/admin/cases/$id/operations", array_merge($body, ['request_id' => str_repeat('b', 64), 'category_code' => 'A03']), 'super');
        $this->assertSame(409, $s3, 'stale version');
        $this->assertSame('myaduan_conflict', $err['code']);
    }

    public function test_invalid_area_rejected_and_stats(): void
    {
        $id = $this->case_id($this->submit()['reference']);
        [$s] = $this->op('super', $id, 'classify', ['dun_slug' => 'putrajaya']);
        $this->assertSame(422, $s);
        $this->submit();

        [, $stats] = $this->call('GET', '/admin/stats', [], 'super');
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['unclassified']);
        $this->assertSame(2, $stats['unassigned']);
        $this->assertSame(2, $stats['by_status']['DITERIMA']);
    }
}
