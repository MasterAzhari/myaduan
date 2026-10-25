<?php
namespace MyAduan;

/**
 * Case state machine. Pure: takes the current case and the actor, returns the
 * fields to change. Persistence and catalogue checks live in Cases.
 *
 * DITERIMA → DALAM SEMAKAN → DALAM TINDAKAN → MENUNGGU PENGESAHAN → DITUTUP
 *                                   ↑________________|  (return)       |
 *                                   ↑__________________________________| (reopen)
 */
final class Workflow
{
    public const RECEIVED = 'DITERIMA';
    public const SCREENING = 'DALAM SEMAKAN';
    public const IN_ACTION = 'DALAM TINDAKAN';
    public const AWAITING_CLOSE = 'MENUNGGU PENGESAHAN';
    public const CLOSED = 'DITUTUP';

    public const STATUSES = [self::RECEIVED, self::SCREENING, self::IN_ACTION, self::AWAITING_CLOSE, self::CLOSED];
    public const PRIORITIES = ['BIASA', 'SEGERA', 'KECEMASAN'];
    public const CLOSURE_REASONS = ['Selesai', 'Duplikasi', 'Ditarik balik', 'Di luar skop'];

    /** How an open case is being handled, set by a decision operation. */
    public const TRACKS = ['AGENSI' => 'Dipanjangkan ke agensi', 'SENDIRI' => 'Tindakan sendiri', 'MAKLUMAT' => 'Perlu maklumat lanjut'];
    /** Decisions the case owner or a Super Admin may take; a Super Admin taking one on an unowned case becomes its owner. */
    private const DECISION_OPS = ['refer' => 'AGENSI', 'self' => 'SENDIRI', 'need_info' => 'MAKLUMAT'];

    /** Operations only a Super Admin (manager) may perform. */
    private const MANAGER_OPS = ['classify', 'assign', 'approve_close', 'return', 'reopen', 'publish'];
    /** Operations only the assigned owner may perform, even for a manager. */
    private const OWNER_OPS = ['accept', 'action', 'request_close'];

    public const OPERATIONS = [
        'classify' => 'Kelaskan aduan',
        'assign' => 'Simpan & tugaskan',
        'accept' => 'Terima tugasan',
        'action' => 'Rekod tindakan',
        'comment' => 'Catatan dalaman',
        'request_close' => 'Mohon penutupan',
        'approve_close' => 'Sahkan & tutup',
        'return' => 'Kembalikan untuk tindakan',
        'reopen' => 'Buka semula',
        'publish' => 'Kemas kini mesej awam',
        'refer' => 'Panjangkan ke agensi',
        'self' => 'Tindakan sendiri',
        'need_info' => 'Perlu maklumat lanjut',
        'agency_update' => 'Kemas kini status agensi',
    ];

    /**
     * @param array{status:string, owner_id:int, accepted_at:?string, first_action_at:?string,
     *              public_draft:?string, reopen_count:int, track?:?string, referral_count?:int} $case
     * @param array{id:int, manager:bool} $actor
     * @param array<string, mixed> $input Already sanitized strings.
     * @return array{status:string, set:array<string, mixed>, note:string}
     * @throws WorkflowException
     */
    public static function apply(array $case, string $op, array $actor, array $input, string $now): array
    {
        if (!isset(self::OPERATIONS[$op])) {
            throw new WorkflowException('Tindakan tidak sah.');
        }
        $status = $case['status'];
        $owner = (int) $case['owner_id'];
        $is_owner = $owner > 0 && $owner === (int) $actor['id'];

        if (in_array($op, self::MANAGER_OPS, true) && !$actor['manager']) {
            throw new WorkflowException('Tindakan ini memerlukan Super Admin.');
        }
        if (in_array($op, self::OWNER_OPS, true) && !$is_owner) {
            throw new WorkflowException('Tindakan ini hanya untuk pegawai yang ditugaskan. Super Admin boleh tugaskan kes kepada diri sendiri.');
        }
        if ($op === 'comment' && !$actor['manager'] && !$is_owner) {
            throw new WorkflowException('Hanya Super Admin atau pegawai kes boleh menambah catatan.');
        }
        if ((isset(self::DECISION_OPS[$op]) || $op === 'agency_update') && !$actor['manager'] && !$is_owner) {
            throw new WorkflowException('Hanya pegawai kes atau Super Admin boleh membuat keputusan ini.');
        }
        if ($status === self::CLOSED && !in_array($op, ['reopen', 'comment', 'assign'], true)) {
            throw new WorkflowException('Kes telah ditutup. Buka semula dahulu.');
        }

        $note = trim((string) ($input['note'] ?? ''));
        $set = [];

        switch ($op) {
            case 'classify':
                $set = self::classification($input);
                if (!$set) {
                    throw new WorkflowException('Tiada maklumat klasifikasi untuk disimpan.');
                }
                $status = $status === self::RECEIVED ? self::SCREENING : $status;
                break;

            case 'assign':
                $new_owner = (int) ($input['owner_id'] ?? 0);
                if ($new_owner <= 0) {
                    throw new WorkflowException('Pilih pegawai untuk ditugaskan.');
                }
                $set = self::classification($input);
                if ($new_owner !== $owner) {
                    $set += ['owner_id' => $new_owner, 'assigned_at' => $now, 'accepted_at' => null];
                } elseif (!$set) {
                    throw new WorkflowException('Kes sudah ditugaskan kepada pegawai ini.');
                }
                $status = $status === self::RECEIVED ? self::SCREENING : $status;
                break;

            case 'accept':
                if (!empty($case['accepted_at'])) {
                    throw new WorkflowException('Tugasan telah diterima.');
                }
                if (!in_array($status, [self::RECEIVED, self::SCREENING, self::IN_ACTION], true)) {
                    throw new WorkflowException('Kes tidak berada dalam peringkat penerimaan.');
                }
                $set = ['accepted_at' => $now];
                $status = self::IN_ACTION;
                break;

            case 'action':
                self::require_in_action($case, $status);
                self::require_note($note);
                $set = ['last_action_at' => $now];
                if (empty($case['first_action_at'])) {
                    $set['first_action_at'] = $now;
                }
                if (isset($input['next_action'])) {
                    $set['next_action'] = mb_substr(trim((string) $input['next_action']), 0, 500) ?: null;
                }
                if (array_key_exists('followup_at', $input)) {
                    $set['followup_at'] = self::utc_or_null($input['followup_at']);
                }
                break;

            case 'comment':
                self::require_note($note);
                break;

            case 'request_close':
                self::require_in_action($case, $status);
                $reason = (string) ($input['closure_reason'] ?? '');
                if (!in_array($reason, self::CLOSURE_REASONS, true)) {
                    throw new WorkflowException('Pilih sebab penutupan.');
                }
                $resolution = trim((string) ($input['resolution'] ?? ''));
                if (mb_strlen($resolution) < 10) {
                    throw new WorkflowException('Terangkan penyelesaian (sekurang-kurangnya 10 aksara).');
                }
                $draft = trim((string) ($input['public_draft'] ?? ''));
                if (mb_strlen($draft) < 10) {
                    throw new WorkflowException('Tulis draf mesej awam untuk pengadu.');
                }
                $set = ['closure_reason' => $reason, 'resolution' => $resolution, 'public_draft' => $draft, 'last_action_at' => $now];
                $status = self::AWAITING_CLOSE;
                break;

            case 'approve_close':
                if ($status !== self::AWAITING_CLOSE) {
                    throw new WorkflowException('Kes belum dimohon untuk penutupan.');
                }
                $message = trim((string) ($input['public_message'] ?? $case['public_draft'] ?? ''));
                if (mb_strlen($message) < 10) {
                    throw new WorkflowException('Mesej awam diperlukan sebelum kes ditutup.');
                }
                $set = ['public_message' => $message, 'closed_at' => $now, 'followup_at' => null];
                $status = self::CLOSED;
                break;

            case 'return':
                if ($status !== self::AWAITING_CLOSE) {
                    throw new WorkflowException('Kes belum dimohon untuk penutupan.');
                }
                self::require_note($note);
                $set = ['closure_reason' => null];
                $status = self::IN_ACTION;
                break;

            case 'reopen':
                if ($status !== self::CLOSED) {
                    throw new WorkflowException('Hanya kes yang ditutup boleh dibuka semula.');
                }
                self::require_note($note);
                $set = ['closed_at' => null, 'closure_reason' => null, 'reopen_count' => (int) $case['reopen_count'] + 1];
                $status = self::IN_ACTION;
                break;

            case 'refer':
            case 'self':
            case 'need_info':
                if ($status === self::AWAITING_CLOSE) {
                    throw new WorkflowException('Kes sedang menunggu pengesahan penutupan.');
                }
                if ($op === 'refer') {
                    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['agency_ids'] ?? [])))));
                    if (!$ids) {
                        throw new WorkflowException('Pilih sekurang-kurangnya satu agensi.');
                    }
                    if (count($ids) > Agencies::MAX_PER_REFERRAL) {
                        throw new WorkflowException('Pilih paling banyak ' . Agencies::MAX_PER_REFERRAL . ' agensi sekali hantar.');
                    }
                    if (mb_strlen($note) < 10) {
                        throw new WorkflowException('Tulis mesej kepada agensi (sekurang-kurangnya 10 aksara).');
                    }
                }
                if ($op === 'need_info' && mb_strlen($note) < 10) {
                    throw new WorkflowException('Nyatakan maklumat yang diperlukan (sekurang-kurangnya 10 aksara).');
                }
                $set = ['track' => self::DECISION_OPS[$op], 'last_action_at' => $now];
                if (empty($case['first_action_at'])) {
                    $set['first_action_at'] = $now;
                }
                if ($owner === 0) {
                    $set += ['owner_id' => (int) $actor['id'], 'assigned_at' => $now];
                    $is_owner = true;
                }
                if ($is_owner && empty($case['accepted_at'])) {
                    $set['accepted_at'] = $now;
                }
                if (isset($input['next_action'])) {
                    $set['next_action'] = mb_substr(trim((string) $input['next_action']), 0, 500) ?: null;
                }
                if (array_key_exists('followup_at', $input)) {
                    $set['followup_at'] = self::utc_or_null($input['followup_at']);
                }
                $status = self::IN_ACTION;
                break;

            case 'agency_update':
                if (empty($case['referral_count'])) {
                    throw new WorkflowException('Kes ini belum dipanjangkan ke mana-mana agensi.');
                }
                if ((int) ($input['referral_id'] ?? 0) <= 0) {
                    throw new WorkflowException('Pilih rujukan agensi untuk dikemas kini.');
                }
                if (!in_array($input['agency_status'] ?? '', Agencies::STATUSES, true)) {
                    throw new WorkflowException('Pilih status agensi.');
                }
                $set = ['last_action_at' => $now];
                break;

            case 'publish':
                $message = trim((string) ($input['public_message'] ?? ''));
                if (mb_strlen($message) < 10) {
                    throw new WorkflowException('Mesej awam terlalu pendek.');
                }
                $set = ['public_message' => $message];
                break;
        }

        return ['status' => $status, 'set' => $set, 'note' => $note];
    }

    /** Classification fields a manager may set. Values are validated against the catalogue in Cases. */
    private static function classification(array $input): array
    {
        $set = [];
        foreach (['category_code', 'dun_slug', 'area_id', 'title'] as $key) {
            if (array_key_exists($key, $input)) {
                $set[$key] = $input[$key] === '' || $input[$key] === null ? null : $input[$key];
            }
        }
        if (isset($input['priority'])) {
            if (!in_array($input['priority'], self::PRIORITIES, true)) {
                throw new WorkflowException('Tahap keutamaan tidak sah.');
            }
            $set['priority'] = $input['priority'];
        }
        if (array_key_exists('title', $set) && $set['title'] !== null) {
            $set['title'] = mb_substr(trim((string) $set['title']), 0, 150);
            if ($set['title'] === '') {
                throw new WorkflowException('Tajuk tidak boleh kosong.');
            }
        }
        return $set;
    }

    private static function require_in_action(array $case, string $status): void
    {
        if (empty($case['accepted_at'])) {
            throw new WorkflowException('Terima tugasan dahulu.');
        }
        if ($status !== self::IN_ACTION) {
            throw new WorkflowException('Kes perlu dalam tindakan untuk kemas kini ini.');
        }
    }

    private static function require_note(string $note): void
    {
        if (mb_strlen($note) < 10) {
            throw new WorkflowException('Catatan dalaman diperlukan (sekurang-kurangnya 10 aksara).');
        }
    }

    /** Accepts "YYYY-MM-DDTHH:MM" in Malaysia time; returns UTC "Y-m-d H:i:s". */
    public static function utc_or_null(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) $value, $tz);
        if (!$d || $d->format('Y-m-d\TH:i') !== $value) {
            throw new WorkflowException('Tarikh susulan tidak sah.');
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
