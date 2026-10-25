<?php
namespace MyAduan;

use MyAduan\Rest\FieldErrors;

if (!defined('ABSPATH')) {
    exit;
}

/** Categories and DUN → locality master data, stored in MySQL and seeded from data/*.json. */
final class Catalog
{
    private ?array $categories = null;
    private ?array $areas = null;
    /** Bumped when localities change, so long-lived instances (the Cases service) reload them. */
    private static int $generation = 0;
    private int $loaded = 0;

    public function categories(): array
    {
        global $wpdb;
        return $this->categories ??= $wpdb->get_results(
            'SELECT code, name, group_name, group_order FROM ' . Schema::table('categories') . ' WHERE active = 1 ORDER BY group_order, code',
            ARRAY_A
        );
    }

    public function has_category(string $code): bool
    {
        return in_array($code, array_column($this->categories(), 'code'), true);
    }

    /** @return list<array{slug:string, name:string, localities:list<array{id:int, name:string}>}> */
    public function areas(): array
    {
        if ($this->areas !== null && $this->loaded === self::$generation) {
            return $this->areas;
        }
        $this->loaded = self::$generation;
        global $wpdb;
        $duns = $wpdb->get_results('SELECT slug, name FROM ' . Schema::table('duns') . ' WHERE active = 1 ORDER BY sort_order, name', ARRAY_A);
        $rows = $wpdb->get_results('SELECT id, dun_slug, name FROM ' . Schema::table('localities') . ' WHERE active = 1 ORDER BY name', ARRAY_A);
        $by_dun = [];
        foreach ($rows as $r) {
            $by_dun[$r['dun_slug']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        foreach ($duns as &$d) {
            $d['localities'] = $by_dun[$d['slug']] ?? [];
        }
        return $this->areas = $duns;
    }

    public function has_dun(string $slug): bool
    {
        return in_array($slug, array_column($this->areas(), 'slug'), true);
    }

    public function locality_in_dun(int $id, string $dun): bool
    {
        foreach ($this->areas() as $d) {
            if ($d['slug'] === $dun) {
                return in_array($id, array_column($d['localities'], 'id'), true);
            }
        }
        return false;
    }

    /** Names of every locality, hidden ones included, so old cases still show theirs. @return array<int, string> */
    public function locality_names(): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results('SELECT id, name FROM ' . Schema::table('localities'), ARRAY_A) as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    /**
     * The Kawasan page: every DUN with all its localities, hidden ones included,
     * and how many cases use each.
     *
     * @return list<array{slug:string, name:string, localities:list<array{id:int, name:string, active:bool, cases:int}>}>
     */
    public function admin_areas(): array
    {
        global $wpdb;
        $duns = $wpdb->get_results('SELECT slug, name FROM ' . Schema::table('duns') . ' WHERE active = 1 ORDER BY sort_order, name', ARRAY_A);
        $rows = $wpdb->get_results(
            'SELECT l.id, l.dun_slug, l.name, l.active, COUNT(c.id) cases FROM ' . Schema::table('localities') . ' l
             LEFT JOIN ' . Schema::table('cases') . ' c ON c.area_id = l.id GROUP BY l.id ORDER BY l.name',
            ARRAY_A
        );
        $by_dun = [];
        foreach ($rows as $r) {
            $by_dun[$r['dun_slug']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'active' => (bool) $r['active'], 'cases' => (int) $r['cases']];
        }
        foreach ($duns as &$d) {
            $d['localities'] = $by_dun[$d['slug']] ?? [];
        }
        return $duns;
    }

    /**
     * Adds localities to a DUN, one name per line. Names already there are
     * skipped; a hidden one with the same name is shown again.
     *
     * @return array{added:int, restored:int, skipped:int, ids:list<int>}
     */
    public function add_localities(string $dun, string $names): array
    {
        global $wpdb;
        $table = Schema::table('localities');
        if (!$this->has_dun($dun)) {
            throw new FieldErrors(['dun_slug' => 'Pilih DUN.']);
        }
        $list = [];
        foreach (preg_split('/\r\n|\r|\n/', $names) as $line) {
            $name = self::locality_name($line);
            if ($name !== '') {
                $list[mb_strtolower($name)] ??= $name;
            }
        }
        if (!$list) {
            throw new FieldErrors(['names' => 'Masukkan sekurang-kurangnya satu nama taman atau kampung.']);
        }
        if (count($list) > 500) {
            throw new FieldErrors(['names' => 'Paling banyak 500 nama sekali tambah.']);
        }
        foreach ($list as $name) {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
                throw new FieldErrors(['names' => "Nama \"$name\" mesti 2 hingga 150 aksara."]);
            }
        }
        $out = ['added' => 0, 'restored' => 0, 'skipped' => 0, 'ids' => []];
        foreach ($list as $name) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, active FROM $table WHERE dun_slug = %s AND name = %s", $dun, $name), ARRAY_A);
            if ($row && (int) $row['active']) {
                $out['skipped']++;
            } elseif ($row) {
                $wpdb->update($table, ['active' => 1], ['id' => (int) $row['id']]);
                $out['restored']++;
            } else {
                $wpdb->insert($table, ['dun_slug' => $dun, 'name' => $name, 'active' => 1]);
                $row = ['id' => $wpdb->insert_id];
                $out['added']++;
            }
            $out['ids'][] = (int) $row['id'];
        }
        self::$generation++;
        return $out;
    }

    /** Renames, hides or shows one locality. Hiding keeps it on the cases that already use it. */
    public function update_locality(int $id, array $in): array
    {
        global $wpdb;
        $table = Schema::table('localities');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, dun_slug, name, active FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            throw new WorkflowException('Taman/kampung tidak ditemui.', 'not_found', 404);
        }
        $data = [];
        if (array_key_exists('name', $in)) {
            $name = self::locality_name((string) $in['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
                throw new FieldErrors(['name' => 'Masukkan nama 2 hingga 150 aksara.']);
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE dun_slug = %s AND name = %s AND id <> %d", $row['dun_slug'], $name, $id))) {
                throw new FieldErrors(['name' => 'Nama ini sudah ada dalam DUN yang sama.']);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('active', $in)) {
            $data['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($data) {
            $wpdb->update($table, $data, ['id' => $id]);
        }
        self::$generation++;
        $row = array_merge($row, $data);
        return ['id' => $id, 'dun_slug' => $row['dun_slug'], 'name' => $row['name'], 'active' => (bool) $row['active']];
    }

    private static function locality_name(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', sanitize_text_field($raw)));
    }

    /** Idempotent upsert from the bundled JSON files. Existing names are refreshed, nothing is deleted. */
    public static function seed(): void
    {
        global $wpdb;
        $cats = json_decode((string) file_get_contents(MYADUAN_DIR . '/data/categories.json'), true) ?: [];
        foreach ($cats as $c) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . Schema::table('categories') . ' (code, name, group_name, group_order, active) VALUES (%s, %s, %s, %d, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), group_name = VALUES(group_name), group_order = VALUES(group_order)',
                $c['code'], $c['name'], $c['group'], $c['group_order']
            ));
        }
        $areas = json_decode((string) file_get_contents(MYADUAN_DIR . '/data/areas.json'), true) ?: [];
        foreach ($areas as $i => $a) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . Schema::table('duns') . ' (slug, name, sort_order, active) VALUES (%s, %s, %d, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order)',
                $a['slug'], $a['dun'], $i
            ));
            foreach ($a['localities'] as $name) {
                $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . Schema::table('localities') . ' (dun_slug, name, active) VALUES (%s, %s, 1)',
                    $a['slug'], $name
                ));
            }
        }
    }
}
