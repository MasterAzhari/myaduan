<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Categories and DUN → locality master data, stored in MySQL and seeded from data/*.json. */
final class Catalog
{
    private ?array $categories = null;
    private ?array $areas = null;

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
        if ($this->areas !== null) {
            return $this->areas;
        }
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
