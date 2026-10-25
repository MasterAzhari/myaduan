<?php
namespace MyAduan;

use MyAduan\Rest\FieldErrors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agencies a case can be referred to, and the referrals themselves.
 *
 * The list is seeded once from data/agencies.json and then belongs to the
 * Super Admin: later upgrades never overwrite or re-add entries.
 */
final class Agencies
{
    public const STATUSES = ['Dirujuk', 'Dalam tindakan agensi', 'Selesai oleh agensi', 'Ditolak agensi'];
    public const MAX_PER_REFERRAL = 5;

    public static function seed(): void
    {
        global $wpdb;
        $table = Schema::table('agency_list');
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") > 0) {
            return;
        }
        $rows = json_decode((string) file_get_contents(MYADUAN_DIR . '/data/agencies.json'), true) ?: [];
        foreach ($rows as $i => $a) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (name, officer, email, cc, sort_order, active) VALUES (%s, %s, %s, %s, %d, 1)",
                $a['name'], $a['officer'], $a['email'], implode(', ', $a['cc']), $i + 1
            ));
        }
    }

    /** @return list<array{id:int, name:string, officer:string, email:string, cc:list<string>, active:bool}> */
    public function all(bool $active_only = false): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . Schema::table('agency_list') . ($active_only ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name',
            ARRAY_A
        );
        return array_map([self::class, 'shape'], $rows);
    }

    public function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('agency_list') . ' WHERE id = %d', $id), ARRAY_A);
        return $row ? self::shape($row) : null;
    }

    /** Creates (id 0) or updates an agency. */
    public function save(int $id, array $in): array
    {
        global $wpdb;
        $table = Schema::table('agency_list');
        $errors = [];
        $name = trim(sanitize_text_field((string) ($in['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Masukkan nama agensi (2 hingga 150 aksara).';
        } elseif ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name = %s AND id <> %d", $name, $id))) {
            $errors['name'] = 'Agensi dengan nama ini sudah ada.';
        }
        $officer = mb_substr(trim(sanitize_text_field((string) ($in['officer'] ?? ''))), 0, 200);
        $email = strtolower(sanitize_email((string) ($in['email'] ?? '')));
        if (!is_email($email)) {
            $errors['email'] = 'Masukkan e-mel utama agensi yang sah.';
        }
        $cc = [];
        foreach (preg_split('/[\s,;]+/', (string) ($in['cc'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $addr) {
            $addr = strtolower(sanitize_email($addr));
            if (!is_email($addr)) {
                $errors['cc'] = 'Ada alamat CC yang tidak sah. Asingkan dengan koma.';
                break;
            }
            if ($addr !== $email) {
                $cc[$addr] = $addr;
            }
        }
        if (mb_strlen(implode(', ', $cc)) > 1000) {
            $errors['cc'] = 'Senarai CC terlalu panjang.';
        }
        if ($errors) {
            throw new FieldErrors($errors);
        }
        $data = ['name' => $name, 'officer' => $officer, 'email' => $email, 'cc' => implode(', ', $cc)];
        if (array_key_exists('active', $in)) {
            $data['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if ($id) {
            if (!$this->get($id)) {
                throw new WorkflowException('Agensi tidak ditemui.', 'not_found', 404);
            }
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['sort_order'] = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM $table");
            $data['active'] ??= 1;
            $wpdb->insert($table, $data);
            $id = (int) $wpdb->insert_id;
        }
        return $this->get($id);
    }

    /** @return list<array> referrals of a case, newest first */
    public function referrals(int $case_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT r.*, l.name agency_name, l.email agency_email FROM ' . Schema::table('agencies') . ' r
             LEFT JOIN ' . Schema::table('agency_list') . ' l ON l.id = r.agency_id WHERE r.case_id = %d ORDER BY r.id DESC',
            $case_id
        ), ARRAY_A);
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'agency_id' => (int) $r['agency_id'],
            'agency_name' => $r['agency_name'] ?? 'Agensi #' . $r['agency_id'],
            'agency_email' => $r['agency_email'],
            'status' => $r['status'],
            'reference_no' => $r['reference_no'],
            'note' => $r['note'],
            'created_at' => Cases::iso($r['created_at']),
            'updated_at' => Cases::iso($r['updated_at']),
        ], $rows);
    }

    private static function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'officer' => $r['officer'],
            'email' => $r['email'],
            'cc' => $r['cc'] === '' ? [] : array_map('trim', explode(',', $r['cc'])),
            'active' => (bool) $r['active'],
        ];
    }
}
