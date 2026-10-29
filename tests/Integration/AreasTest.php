<?php
use MyAduan\Schema;
use MyAduan\Tests\Integration\TestCase;

/** v0.7.0: the Super Admin keeps the taman/kampung list of each DUN in the portal. */
final class AreasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . Schema::table('localities'));
    }

    public function test_super_admin_pastes_a_list_and_classifies_with_it(): void
    {
        $this->assertSame(403, $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => 'Taman Melati'], 'a01')[0]);
        $this->assertSame(403, $this->call('GET', '/admin/areas', [], 'viewer')[0]);

        [$status, $data] = $this->call('POST', '/admin/areas', ['dun_slug' => 'putrajaya', 'names' => 'Taman Melati'], 'super');
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('dun_slug', $data['data']['fields']);
        [$status, $data] = $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => "  \n "], 'super');
        $this->assertArrayHasKey('names', $data['data']['fields']);

        [$status, $r] = $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => "Taman Melati\r\n  Kampung   Changkat \n\ntaman melati\nTaman Greenwood"], 'super');
        $this->assertSame(201, $status, wp_json_encode($r));
        $this->assertSame([3, 0, 0], [$r['added'], $r['restored'], $r['skipped']]);
        [, $r2] = $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => "Taman Melati\nTaman Baru"], 'super');
        $this->assertSame([1, 1], [$r2['added'], $r2['skipped']]);
        $this->assertSame($r['ids'][0], $r2['ids'][0], 'existing name returns its id');

        [, $catalog] = $this->call('GET', '/admin/catalog', [], 'super');
        $tua = array_values(array_filter($catalog['areas'], fn($d) => $d['slug'] === 'sungai-tua'))[0];
        $this->assertSame(['Kampung Changkat', 'Taman Baru', 'Taman Greenwood', 'Taman Melati'], array_column($tua['localities'], 'name'));

        $melati = $r['ids'][0];
        $id = $this->case_id($this->submit()['reference']);
        [$s] = $this->op('super', $id, 'classify', ['dun_slug' => 'gombak-setia', 'area_id' => $melati]);
        $this->assertSame(422, $s, 'taman must belong to the chosen DUN');
        [$s, $c] = $this->op('super', $id, 'classify', ['dun_slug' => 'sungai-tua', 'area_id' => $melati]);
        $this->assertSame(200, $s, wp_json_encode($c));

        // Hidden: no longer offered, but the case keeps it and can still be saved.
        [$s, $u] = $this->call('POST', '/admin/areas/' . $melati, ['active' => false], 'super');
        $this->assertSame([200, false], [$s, $u['active']]);
        [, $catalog] = $this->call('GET', '/admin/catalog', [], 'super');
        $tua = array_values(array_filter($catalog['areas'], fn($d) => $d['slug'] === 'sungai-tua'))[0];
        $this->assertNotContains('Taman Melati', array_column($tua['localities'], 'name'));
        $this->assertSame('Taman Melati', $catalog['locality_names'][(string) $melati]);
        [$s] = $this->op('super', $id, 'classify', ['dun_slug' => 'sungai-tua', 'area_id' => $melati, 'priority' => 'SEGERA']);
        $this->assertSame(200, $s, 'unchanged hidden taman is kept');
        $other = $this->case_id($this->submit()['reference']);
        [$s] = $this->op('super', $other, 'classify', ['dun_slug' => 'sungai-tua', 'area_id' => $melati]);
        $this->assertSame(422, $s, 'hidden taman cannot be picked for another case');

        [, $areas] = $this->call('GET', '/admin/areas', [], 'super');
        $row = array_values(array_filter($areas[0]['localities'], fn($l) => $l['id'] === $melati))[0];
        $this->assertSame([false, 1], [$row['active'], $row['cases']]);

        // Pasting the name again shows it again; renaming checks duplicates.
        [, $r3] = $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => 'Taman Melati'], 'super');
        $this->assertSame(1, $r3['restored']);
        [$s, $data] = $this->call('POST', '/admin/areas/' . $melati, ['name' => 'Taman Greenwood'], 'super');
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('name', $data['data']['fields']);
        [$s, $u] = $this->call('POST', '/admin/areas/' . $melati, ['name' => 'Taman Melati Jaya'], 'super');
        $this->assertSame([200, 'Taman Melati Jaya'], [$s, $u['name']]);
        $this->assertSame(404, $this->call('POST', '/admin/areas/999999', ['name' => 'X Y'], 'super')[0]);
    }

    /** v0.9.0: the public form no longer asks for DUN or taman, so the portal keeps reminding the Super Admin. */
    public function test_cases_without_dun_or_taman_are_counted_and_listed(): void
    {
        global $wpdb;
        [, $r] = $this->call('POST', '/admin/areas', ['dun_slug' => 'sungai-tua', 'names' => 'Taman Melati'], 'super');
        $done = $this->case_id($this->submit()['reference']);
        $half = $this->case_id($this->submit()['reference']);
        $closed = $this->case_id($this->submit()['reference']);
        $this->assertSame(200, $this->op('super', $done, 'classify', ['dun_slug' => 'sungai-tua', 'area_id' => $r['ids'][0]])[0]);
        $this->assertSame(200, $this->op('super', $half, 'classify', ['dun_slug' => 'sungai-tua'])[0]);

        [, $stats] = $this->call('GET', '/admin/stats', [], 'super');
        $this->assertSame(2, $stats['no_area'], 'a DUN without a taman still needs finishing');
        [, $list] = $this->call('GET', '/admin/cases', ['dun' => 'incomplete'], 'super');
        $this->assertEqualsCanonicalizing([$half, $closed], array_column($list['items'], 'id'));

        // A closed case can still get its DUN and taman, without reopening it.
        $wpdb->update(Schema::table('cases'), ['status' => 'DITUTUP', 'owner_id' => 1], ['id' => $closed]);
        [$s, $c] = $this->op('super', $closed, 'classify', ['dun_slug' => 'sungai-tua', 'area_id' => $r['ids'][0]]);
        $this->assertSame([200, 'DITUTUP'], [$s, $c['status']], wp_json_encode($c));
        $this->assertSame(1, $this->call('GET', '/admin/stats', [], 'super')[1]['no_area']);
    }
}
