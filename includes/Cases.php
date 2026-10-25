<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Case persistence. Every write runs in a transaction with an audit event. */
final class Cases
{
    public const PER_PAGE = 25;
    public const TRACK_LIMIT = 100;

    public function __construct(private \wpdb $db, private Crypto $crypto, private Catalog $catalog)
    {
    }

    /**
     * Stores a validated public complaint. Repeating the same client key with the
     * same payload returns the original reference instead of a duplicate.
     *
     * @param array{name:string, ic:string, phone:string, details:string, location:string, lat:?float, lng:?float} $data
     * @param list<array{bytes:string, mime:string, ext:string, name:string}> $images Already processed by ImageProcessor.
     * @return array{id:int, reference:string, duplicate:bool}
     */
    public function create(array $data, string $client_key, string $payload_hash, array $images = []): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $client_key) || !preg_match('/^[a-f0-9]{64}$/D', $payload_hash)) {
            throw new WorkflowException('Kunci penghantaran tidak sah.', 'invalid_key', 400);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $existing = $this->existing_submission($client_key, $payload_hash);
            if ($existing) {
                return $existing;
            }
            $written = [];
            $this->begin();
            try {
                $now = self::now();
                $reference = self::new_reference();
                $ok = $this->db->insert(Schema::table('cases'), [
                    'reference' => $reference,
                    'version' => 1,
                    'status' => Workflow::RECEIVED,
                    'priority' => 'BIASA',
                    'title' => Validator::provisional_title($data['details']),
                    'details' => $data['details'],
                    'location_text' => $data['location'],
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'name_enc' => $this->crypto->encrypt($data['name']),
                    'ic_enc' => $this->crypto->encrypt($data['ic']),
                    'ic_index' => $this->crypto->blind_index($data['ic']),
                    'phone_enc' => $this->crypto->encrypt($data['phone']),
                    'email_enc' => ($data['email'] ?? '') !== '' ? $this->crypto->encrypt($data['email']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($ok === false) {
                    throw new \RuntimeException('insert_case');
                }
                $id = (int) $this->db->insert_id;
                if ($this->db->insert(Schema::table('submissions'), [
                    'client_key' => $client_key, 'payload_hash' => $payload_hash, 'case_id' => $id, 'created_at' => $now,
                ]) === false) {
                    throw new \RuntimeException('insert_submission');
                }
                foreach (Files::write($reference, $images, 'public', 0, $now, $written) as $att) {
                    if ($this->db->insert(Schema::table('attachments'), ['case_id' => $id] + $att) === false) {
                        throw new \RuntimeException('insert_attachment');
                    }
                }
                $this->event($id, 0, 'CIPTA', null, Workflow::RECEIVED, null, $images ? ['gambar' => [0, count($images)]] : null, null, $now);
                $this->commit();
                do_action('myaduan_case_created', $id, $reference);
                return ['id' => $id, 'reference' => $reference, 'duplicate' => false];
            } catch (\RuntimeException $e) {
                $this->rollback();
                Files::delete_paths($written);
                // A concurrent request with the same key, or a reference collision: look again and retry.
                if ($attempt === 2) {
                    throw new WorkflowException('Aduan belum dapat disimpan. Cuba hantar semula.', 'save_failed', 500);
                }
            }
        }
        throw new WorkflowException('Aduan belum dapat disimpan. Cuba hantar semula.', 'save_failed', 500);
    }

    /** The earlier result for this client key, or null. Throws if the key was used for other content. */
    public function existing_submission(string $client_key, string $payload_hash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $client_key)) {
            return null;
        }
        $row = $this->db->get_row($this->db->prepare(
            'SELECT s.payload_hash, c.id, c.reference FROM ' . Schema::table('submissions') . ' s JOIN ' . Schema::table('cases') . ' c ON c.id = s.case_id WHERE s.client_key = %s',
            $client_key
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        if (!hash_equals($row['payload_hash'], $payload_hash)) {
            throw new WorkflowException('Kod penghantaran ini sudah digunakan untuk maklumat lain. Muat semula borang.', 'duplicate_key', 409);
        }
        return ['id' => (int) $row['id'], 'reference' => $row['reference'], 'duplicate' => true];
    }

    /** Public lookup by exact reference or IC. Returns only non-identifying fields. */
    public function track(string $query): array
    {
        $ref = strtoupper(trim($query));
        if (preg_match('/^GOM-\d{8}-[A-F0-9]{10}$/D', $ref)) {
            $where = $this->db->prepare('reference = %s', $ref);
        } elseif ($ic = Validator::normalize_ic($query)) {
            $where = $this->db->prepare('ic_index = %s', $this->crypto->blind_index($ic));
        } else {
            throw new WorkflowException('Masukkan nombor rujukan atau No. IC yang sah.', 'invalid_query', 400);
        }
        $rows = $this->db->get_results(
            'SELECT reference, status, public_message, created_at, updated_at FROM ' . Schema::table('cases')
            . " WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . (self::TRACK_LIMIT + 1),
            ARRAY_A
        );
        return [
            'items' => array_map(static fn(array $r): array => [
                'reference' => $r['reference'],
                'status' => $r['status'],
                'public_message' => $r['public_message'],
                'created_at' => self::iso($r['created_at']),
                'updated_at' => self::iso($r['updated_at']),
            ], array_slice($rows, 0, self::TRACK_LIMIT)),
            'has_more' => count($rows) > self::TRACK_LIMIT,
        ];
    }

    /** SQL fragment limiting rows to what the actor may see. */
    private function scope(array $actor): string
    {
        if ($actor['manager']) {
            return '1=1';
        }
        if (!$actor['work']) {
            return '1=0';
        }
        $own = $this->db->prepare('c.owner_id = %d', $actor['id']);
        $policy = $actor['policy'];
        if ($policy['mode'] === 'all') {
            return "($own OR c.category_code IS NOT NULL)";
        }
        if ($policy['mode'] === 'selected' && $policy['codes']) {
            $in = implode(',', array_map(fn(string $code): string => $this->db->prepare('%s', $code), $policy['codes']));
            return "($own OR c.category_code IN ($in))";
        }
        return "($own)";
    }

    /**
     * @param array{status?:string, owner?:string, category?:string, dun?:string, area?:int, q?:string, ic?:string, from?:string, to?:string} $f
     * @return array{items:list<array>, total:int, page:int, per_page:int}
     */
    public function list(array $actor, array $f, int $page = 1): array
    {
        $where = [$this->scope($actor)];
        if (!empty($f['status'])) {
            if (!in_array($f['status'], Workflow::STATUSES, true)) {
                throw new WorkflowException('Status tidak sah.', 'invalid_filter', 400);
            }
            $where[] = $this->db->prepare('c.status = %s', $f['status']);
        }
        if (isset($f['owner']) && $f['owner'] !== '') {
            $where[] = $f['owner'] === 'unassigned' ? 'c.owner_id = 0' : $this->db->prepare('c.owner_id = %d', (int) $f['owner']);
        }
        if (!empty($f['category'])) {
            $where[] = $f['category'] === 'none' ? 'c.category_code IS NULL' : $this->db->prepare('c.category_code = %s', $f['category']);
        }
        if (!empty($f['dun'])) {
            $where[] = $f['dun'] === 'none' ? 'c.dun_slug IS NULL' : $this->db->prepare('c.dun_slug = %s', $f['dun']);
        }
        if (!empty($f['flag'])) {
            $open = "c.status <> 'DITUTUP'";
            $where[] = match ($f['flag']) {
                'open' => $open,
                'not_accepted' => "$open AND c.owner_id > 0 AND c.accepted_at IS NULL",
                'overdue' => $this->db->prepare("$open AND c.followup_at IS NOT NULL AND c.followup_at < %s", self::now()),
                'agency' => "$open AND c.track = 'AGENSI'",
                'need_info' => "$open AND c.track = 'MAKLUMAT'",
                default => throw new WorkflowException('Penapis tidak sah.', 'invalid_filter', 400),
            };
        }
        if (!empty($f['area'])) {
            $where[] = $this->db->prepare('c.area_id = %d', (int) $f['area']);
        }
        if (!empty($f['q'])) {
            $like = '%' . $this->db->esc_like(trim($f['q'])) . '%';
            $where[] = $this->db->prepare('(c.reference LIKE %s OR c.title LIKE %s OR c.location_text LIKE %s)', strtoupper($like), $like, $like);
        }
        if (!empty($f['ic'])) {
            $ic = Validator::normalize_ic($f['ic']);
            $where[] = $ic ? $this->db->prepare('c.ic_index = %s', $this->crypto->blind_index($ic)) : '1=0';
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if (!empty($f[$key])) {
                $where[] = $this->db->prepare("c.created_at $op %s", self::my_date_to_utc($f[$key], $key === 'to'));
            }
        }
        $sql_where = implode(' AND ', $where);
        $table = Schema::table('cases');
        $total = (int) $this->db->get_var("SELECT COUNT(*) FROM $table c WHERE $sql_where");
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = $this->db->get_results(
            "SELECT c.id, c.reference, c.version, c.status, c.priority, c.title, c.location_text, c.category_code, c.dun_slug, c.area_id,
                    c.owner_id, c.track, c.name_enc, c.created_at, c.updated_at, c.accepted_at, c.followup_at
             FROM $table c WHERE $sql_where ORDER BY c.created_at DESC, c.id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset",
            ARRAY_A
        );
        $names = $this->user_names(array_column($rows, 'owner_id'));
        $items = array_map(function (array $r) use ($names): array {
            return [
                'id' => (int) $r['id'],
                'reference' => $r['reference'],
                'version' => (int) $r['version'],
                'status' => $r['status'],
                'priority' => $r['priority'],
                'title' => $r['title'],
                'location' => $r['location_text'],
                'category_code' => $r['category_code'],
                'dun_slug' => $r['dun_slug'],
                'area_id' => $r['area_id'] ? (int) $r['area_id'] : null,
                'owner_id' => (int) $r['owner_id'],
                'owner_name' => $names[(int) $r['owner_id']] ?? null,
                'complainant' => $this->crypto->decrypt($r['name_enc']),
                'track' => $r['track'],
                'accepted' => $r['accepted_at'] !== null,
                'created_at' => self::iso($r['created_at']),
                'updated_at' => self::iso($r['updated_at']),
                'followup_at' => self::iso($r['followup_at']),
            ];
        }, $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }

    /** Full case for an authorized actor. Viewing personal data is logged. */
    public function get(array $actor, int $id, bool $log = true): array
    {
        $row = $this->row($id);
        if (!$row || !Access::can_view($actor, self::access_shape($row))) {
            // Same answer for missing and forbidden, so IDs cannot be probed.
            throw new WorkflowException('Aduan tidak ditemui.', 'not_found', 404);
        }
        if ($log) {
            $this->db->insert(Schema::table('access_log'), ['case_id' => $id, 'actor_id' => $actor['id'], 'what' => 'BUKA_KES', 'created_at' => self::now()]);
        }
        $events = $this->db->get_results($this->db->prepare(
            'SELECT id, actor_id, event, status_from, status_to, note, changes, created_at FROM ' . Schema::table('events') . ' WHERE case_id = %d ORDER BY id DESC',
            $id
        ), ARRAY_A);
        $attachments = $this->db->get_results($this->db->prepare(
            'SELECT id, kind, original_name, mime, size, created_at FROM ' . Schema::table('attachments') . ' WHERE case_id = %d ORDER BY id',
            $id
        ), ARRAY_A);
        $names = $this->user_names(array_merge([(int) $row['owner_id']], array_column($events, 'actor_id')));
        $is_owner = (int) $row['owner_id'] === $actor['id'];

        return [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'version' => (int) $row['version'],
            'status' => $row['status'],
            'priority' => $row['priority'],
            'title' => $row['title'],
            'details' => $row['details'],
            'location' => $row['location_text'],
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
            'category_code' => $row['category_code'],
            'dun_slug' => $row['dun_slug'],
            'area_id' => $row['area_id'] ? (int) $row['area_id'] : null,
            'complainant' => [
                'name' => $this->crypto->decrypt($row['name_enc']),
                'ic' => $this->crypto->decrypt($row['ic_enc']),
                'phone' => $this->crypto->decrypt($row['phone_enc']),
                'email' => $row['email_enc'] ? $this->crypto->decrypt($row['email_enc']) : null,
            ],
            'owner_id' => (int) $row['owner_id'],
            'owner_name' => $names[(int) $row['owner_id']] ?? null,
            'next_action' => $row['next_action'],
            'resolution' => $row['resolution'],
            'closure_reason' => $row['closure_reason'],
            'public_draft' => $row['public_draft'],
            'public_message' => $row['public_message'],
            'reopen_count' => (int) $row['reopen_count'],
            'track' => $row['track'],
            'referrals' => (new Agencies())->referrals($id),
            'created_at' => self::iso($row['created_at']),
            'updated_at' => self::iso($row['updated_at']),
            'assigned_at' => self::iso($row['assigned_at']),
            'accepted_at' => self::iso($row['accepted_at']),
            'first_action_at' => self::iso($row['first_action_at']),
            'last_action_at' => self::iso($row['last_action_at']),
            'followup_at' => self::iso($row['followup_at']),
            'closed_at' => self::iso($row['closed_at']),
            'attachments' => array_map(static fn(array $a): array => [
                'id' => (int) $a['id'], 'kind' => $a['kind'], 'name' => $a['original_name'], 'mime' => $a['mime'], 'size' => (int) $a['size'], 'created_at' => self::iso($a['created_at']),
            ], $attachments),
            'events' => array_map(static fn(array $e): array => [
                'id' => (int) $e['id'],
                'actor_id' => (int) $e['actor_id'],
                'actor_name' => (int) $e['actor_id'] ? ($names[(int) $e['actor_id']] ?? 'Pengguna #' . $e['actor_id'])
                    : ($e['event'] === 'AGENCY_REPLY' ? (string) (json_decode((string) $e['changes'], true)['sender'] ?? 'Agensi') : 'Sistem'),
                'event' => $e['event'],
                'status_from' => $e['status_from'],
                'status_to' => $e['status_to'],
                'note' => $e['note'],
                'changes' => $e['changes'] ? json_decode($e['changes'], true) : null,
                'created_at' => self::iso($e['created_at']),
            ], $events),
            'allowed_operations' => $this->allowed_operations($actor, $row, $is_owner),
        ];
    }

    /**
     * Resolves an attachment for an authorized actor and logs the access.
     *
     * @return array{path:string, mime:string, name:string}
     */
    public function attachment(array $actor, int $attachment_id): array
    {
        $att = $this->db->get_row($this->db->prepare('SELECT * FROM ' . Schema::table('attachments') . ' WHERE id = %d', $attachment_id), ARRAY_A);
        $row = $att ? $this->row((int) $att['case_id']) : null;
        $path = $att ? Files::resolve($att['path']) : null;
        if (!$row || !$path || !Access::can_view($actor, self::access_shape($row))) {
            throw new WorkflowException('Fail tidak ditemui.', 'not_found', 404);
        }
        $this->db->insert(Schema::table('access_log'), ['case_id' => (int) $att['case_id'], 'actor_id' => $actor['id'], 'what' => 'BUKA_FAIL', 'created_at' => self::now()]);
        return ['path' => $path, 'mime' => $att['mime'], 'name' => $att['original_name'] ?: basename($path)];
    }

    /** Hints for the UI; the server still enforces every rule in operate(). */
    private function allowed_operations(array $actor, array $row, bool $is_owner): array
    {
        $ops = [];
        foreach (array_keys(Workflow::OPERATIONS) as $op) {
            try {
                Workflow::apply(self::workflow_shape($row), $op, $actor, self::probe_input($op), self::now());
                $ops[] = $op;
            } catch (WorkflowException) {
            }
        }
        return $ops;
    }

    /** Minimal valid input used only to ask "would this operation be allowed now?". */
    private static function probe_input(string $op): array
    {
        return match ($op) {
            'classify' => ['priority' => 'BIASA'],
            'assign' => ['owner_id' => PHP_INT_MAX],
            'action', 'comment', 'return', 'reopen' => ['note' => str_repeat('x', 10)],
            'request_close' => ['closure_reason' => 'Selesai', 'resolution' => str_repeat('x', 10), 'public_draft' => str_repeat('x', 10)],
            'approve_close', 'publish' => ['public_message' => str_repeat('x', 10)],
            'refer' => ['agency_ids' => [1], 'note' => str_repeat('x', 10)],
            'need_info' => ['note' => str_repeat('x', 10)],
            'agency_update' => ['referral_id' => 1, 'agency_status' => Agencies::STATUSES[0]],
            default => [],
        };
    }

    /**
     * Applies a workflow operation with an optimistic version check.
     * Re-sending the same request_id returns the current case without applying twice.
     */
    public function operate(array $actor, int $id, int $version, string $op, array $input, string $request_id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $request_id)) {
            throw new WorkflowException('ID permintaan tidak sah.', 'invalid_request', 400);
        }
        $done = $this->db->get_var($this->db->prepare('SELECT case_id FROM ' . Schema::table('events') . ' WHERE request_id = %s', $request_id));
        if ($done !== null) {
            if ((int) $done !== $id) {
                throw new WorkflowException('ID permintaan telah digunakan.', 'invalid_request', 400);
            }
            return $this->get($actor, $id, false);
        }

        $this->begin();
        try {
            $row = $this->row($id, true);
            if (!$row || !Access::can_view($actor, self::access_shape($row))) {
                throw new WorkflowException('Aduan tidak ditemui.', 'not_found', 404);
            }
            if ((int) $row['version'] !== $version) {
                throw new WorkflowException('Rekod telah dikemas kini oleh orang lain. Muat semula dahulu.', 'conflict', 409);
            }
            $now = self::now();
            $result = Workflow::apply(self::workflow_shape($row), $op, $actor, $input, $now);
            $set = $result['set'];
            $this->validate_classification($row, $set);

            $changes = [];
            foreach ($set as $field => $value) {
                if ((string) $row[$field] !== (string) $value) {
                    $changes[$field] = [$row[$field], $value];
                }
            }
            $referred = [];
            if ($op === 'refer') {
                $referred = $this->add_referrals($id, $input['agency_ids'], $result['note'], $actor['id'], $now);
                $changes['agencies'] = [null, implode(', ', array_column($referred, 'name'))];
            } elseif ($op === 'agency_update') {
                $changes += $this->update_referral($id, $input, $now);
            }
            $status_changed = $result['status'] !== $row['status'];
            if ($set || $status_changed) {
                $set['status'] = $result['status'];
                $set['updated_at'] = $now;
                $set['version'] = $version + 1;
                $updated = $this->db->update(Schema::table('cases'), $set, ['id' => $id, 'version' => $version]);
                if ($updated !== 1) {
                    throw new WorkflowException('Rekod telah dikemas kini oleh orang lain. Muat semula dahulu.', 'conflict', 409);
                }
            }
            $this->event($id, $actor['id'], strtoupper($op), $row['status'], $result['status'], $result['note'] ?: null, $changes ?: null, $request_id, $now);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
        do_action('myaduan_case_operated', $id, $op, $actor, $row['status'], $result['status'], $changes);
        foreach ($referred as $agency) {
            Mailer::queue_referral($id, $agency, $result['note'], $request_id, (string) $actor['name']);
        }
        return $this->get($actor, $id, false);
    }

    /** @return list<array> the agencies referred to */
    private function add_referrals(int $case_id, array $ids, string $note, int $actor_id, string $now): array
    {
        $agencies = new Agencies();
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $agency_id) {
            $agency = $agencies->get($agency_id);
            if (!$agency || !$agency['active']) {
                throw new WorkflowException('Agensi yang dipilih tidak sah atau tidak aktif.');
            }
            $this->db->insert(Schema::table('agencies'), [
                'case_id' => $case_id, 'agency_id' => $agency_id, 'status' => Agencies::STATUSES[0], 'note' => $note,
                'actor_id' => $actor_id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $agency['referral_id'] = (int) $this->db->insert_id;
            $out[] = $agency;
        }
        return $out;
    }

    private function update_referral(int $case_id, array $input, string $now): array
    {
        $table = Schema::table('agencies');
        $ref = $this->db->get_row($this->db->prepare(
            "SELECT r.*, l.name agency_name FROM $table r LEFT JOIN " . Schema::table('agency_list') . ' l ON l.id = r.agency_id WHERE r.id = %d AND r.case_id = %d',
            (int) $input['referral_id'], $case_id
        ), ARRAY_A);
        if (!$ref) {
            throw new WorkflowException('Rujukan agensi tidak ditemui.');
        }
        $set = ['status' => $input['agency_status'], 'updated_at' => $now];
        if (array_key_exists('reference_no', $input)) {
            $set['reference_no'] = mb_substr(trim((string) $input['reference_no']), 0, 100) ?: null;
        }
        $this->db->update($table, $set, ['id' => $ref['id']]);
        $changes = ['agency' => [null, $ref['agency_name']]];
        if ($ref['status'] !== $set['status']) {
            $changes['agency_status'] = [$ref['status'], $set['status']];
        }
        if (array_key_exists('reference_no', $set) && (string) $ref['reference_no'] !== (string) $set['reference_no']) {
            $changes['agency_reference'] = [$ref['reference_no'], $set['reference_no']];
        }
        return $changes;
    }

    /** Catalogue and assignment eligibility checks for fields a manager is setting. */
    private function validate_classification(array $row, array $set): void
    {
        if (!empty($set['category_code']) && !$this->catalog->has_category($set['category_code'])) {
            throw new WorkflowException('Kategori tidak sah.');
        }
        $dun = array_key_exists('dun_slug', $set) ? $set['dun_slug'] : $row['dun_slug'];
        if ($dun !== null && !$this->catalog->has_dun($dun)) {
            throw new WorkflowException('DUN tidak sah.');
        }
        $area = array_key_exists('area_id', $set) ? $set['area_id'] : $row['area_id'];
        if ($area !== null && $area !== '' && ($dun === null || !$this->catalog->locality_in_dun((int) $area, $dun))) {
            throw new WorkflowException('Taman/kampung tidak berada dalam DUN yang dipilih.');
        }
        $category = array_key_exists('category_code', $set) ? $set['category_code'] : $row['category_code'];
        $owner = (int) ($set['owner_id'] ?? $row['owner_id']);
        if ($owner > 0 && (isset($set['owner_id']) || array_key_exists('category_code', $set))) {
            $user = get_userdata($owner);
            if (!$user || !Access::can_own(Access::actor($user), $category)) {
                throw new WorkflowException('Pegawai ini tiada akses kepada kategori kes. Kelaskan kes atau pilih pegawai lain.');
            }
        }
    }

    /** Headline counts for the actor's scope. */
    public function stats(array $actor): array
    {
        $table = Schema::table('cases');
        $scope = $actor['work'] || $actor['manager'] ? $this->scope($actor) : '1=1';
        $by_status = $this->db->get_results("SELECT c.status, COUNT(*) n FROM $table c WHERE $scope GROUP BY c.status", ARRAY_A);
        $counts = array_fill_keys(Workflow::STATUSES, 0);
        foreach ($by_status as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $open = "c.status <> 'DITUTUP'";
        $now = self::now();
        return [
            'total' => array_sum($counts),
            'by_status' => $counts,
            'unclassified' => (int) $this->db->get_var("SELECT COUNT(*) FROM $table c WHERE $scope AND $open AND c.category_code IS NULL"),
            'unassigned' => (int) $this->db->get_var("SELECT COUNT(*) FROM $table c WHERE $scope AND $open AND c.owner_id = 0"),
            'not_accepted' => (int) $this->db->get_var("SELECT COUNT(*) FROM $table c WHERE $scope AND $open AND c.owner_id > 0 AND c.accepted_at IS NULL"),
            'mine_open' => (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $table c WHERE $open AND c.owner_id = %d", $actor['id'])),
            'overdue' => (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM $table c WHERE $scope AND $open AND c.followup_at IS NOT NULL AND c.followup_at < %s", $now)),
        ];
    }

    private function row(int $id, bool $lock = false): ?array
    {
        return $this->db->get_row($this->db->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . Schema::table('agencies') . ' r WHERE r.case_id = c.id) referral_count FROM ' . Schema::table('cases') . ' c WHERE c.id = %d' . ($lock ? ' FOR UPDATE' : ''),
            $id
        ), ARRAY_A) ?: null;
    }

    private function event(int $case_id, int $actor_id, string $event, ?string $from, ?string $to, ?string $note, ?array $changes, ?string $request_id, string $now): void
    {
        $ok = $this->db->insert(Schema::table('events'), [
            'case_id' => $case_id,
            'actor_id' => $actor_id,
            'event' => $event,
            'status_from' => $from,
            'status_to' => $to,
            'note' => $note,
            'changes' => $changes ? wp_json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            'request_id' => $request_id,
            'created_at' => $now,
        ]);
        if ($ok === false) {
            throw new \RuntimeException('insert_event');
        }
    }

    /** @return array<int, string> */
    private function user_names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $names = [];
        foreach (get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $u) {
            $names[(int) $u->ID] = $u->display_name;
        }
        return $names;
    }

    private static function access_shape(array $row): array
    {
        return ['owner_id' => (int) $row['owner_id'], 'category_code' => $row['category_code']];
    }

    private static function workflow_shape(array $row): array
    {
        return [
            'status' => $row['status'],
            'owner_id' => (int) $row['owner_id'],
            'accepted_at' => $row['accepted_at'],
            'first_action_at' => $row['first_action_at'],
            'public_draft' => $row['public_draft'],
            'reopen_count' => (int) $row['reopen_count'],
            'track' => $row['track'],
            'referral_count' => (int) ($row['referral_count'] ?? 0),
        ];
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        if ($this->db->query('COMMIT') === false) {
            throw new \RuntimeException('commit');
        }
    }

    private function rollback(): void
    {
        $this->db->query('ROLLBACK');
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
    }

    /** Reference: GOM-YYYYMMDD-XXXXXXXXXX with the Malaysian date and 40 random bits. */
    public static function new_reference(): string
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('Ymd');
        return 'GOM-' . $date . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /** "YYYY-MM-DD" in Malaysia time → UTC datetime; $end_exclusive gives the next midnight. */
    private static function my_date_to_utc(string $date, bool $end_exclusive): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Kuala_Lumpur'));
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new WorkflowException('Tarikh tidak sah.', 'invalid_filter', 400);
        }
        if ($end_exclusive) {
            $d = $d->modify('+1 day');
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
