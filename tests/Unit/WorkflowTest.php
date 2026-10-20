<?php
use MyAduan\Workflow;
use MyAduan\WorkflowException;
use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase
{
    private const NOW = '2026-10-21 02:00:00';
    private const MANAGER = ['id' => 1, 'manager' => true];
    private const OFFICER = ['id' => 7, 'manager' => false];
    private const OTHER = ['id' => 8, 'manager' => false];

    private function case(array $over = []): array
    {
        return array_merge([
            'status' => Workflow::RECEIVED, 'owner_id' => 0, 'accepted_at' => null,
            'first_action_at' => null, 'public_draft' => null, 'reopen_count' => 0,
        ], $over);
    }

    private function expectRule(string $fragment): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($fragment, '/') . '/u');
    }

    public function test_full_happy_path(): void
    {
        $c = $this->case();
        $r = Workflow::apply($c, 'assign', self::MANAGER, ['owner_id' => 7, 'category_code' => 'A01', 'priority' => 'SEGERA'], self::NOW);
        $this->assertSame(Workflow::SCREENING, $r['status']);
        $this->assertSame(['category_code' => 'A01', 'priority' => 'SEGERA', 'owner_id' => 7, 'assigned_at' => self::NOW, 'accepted_at' => null], $r['set']);

        $c = $this->case(['status' => $r['status'], 'owner_id' => 7]);
        $r = Workflow::apply($c, 'accept', self::OFFICER, [], self::NOW);
        $this->assertSame(Workflow::IN_ACTION, $r['status']);

        $c['status'] = $r['status'];
        $c['accepted_at'] = self::NOW;
        $r = Workflow::apply($c, 'action', self::OFFICER, ['note' => 'Hubungi MPS untuk turap jalan', 'followup_at' => '2026-10-25T09:00'], self::NOW);
        $this->assertSame(Workflow::IN_ACTION, $r['status']);
        $this->assertSame(self::NOW, $r['set']['first_action_at']);
        $this->assertSame('2026-10-25 01:00:00', $r['set']['followup_at'], 'Malaysia time is stored as UTC');

        $c['first_action_at'] = self::NOW;
        $r = Workflow::apply($c, 'request_close', self::OFFICER, [
            'closure_reason' => 'Selesai', 'resolution' => 'Jalan telah diturap oleh MPS', 'public_draft' => 'Aduan anda telah diselesaikan.',
        ], self::NOW);
        $this->assertSame(Workflow::AWAITING_CLOSE, $r['status']);

        $c = array_merge($c, ['status' => $r['status'], 'public_draft' => 'Aduan anda telah diselesaikan.']);
        $r = Workflow::apply($c, 'approve_close', self::MANAGER, [], self::NOW);
        $this->assertSame(Workflow::CLOSED, $r['status']);
        $this->assertSame('Aduan anda telah diselesaikan.', $r['set']['public_message'], 'Draft is published when no override is given');

        $c['status'] = Workflow::CLOSED;
        $r = Workflow::apply($c, 'reopen', self::MANAGER, ['note' => 'Pengadu kata masih berlubang'], self::NOW);
        $this->assertSame(Workflow::IN_ACTION, $r['status']);
        $this->assertSame(1, $r['set']['reopen_count']);
    }

    public function test_officer_cannot_assign(): void
    {
        $this->expectRule('Super Admin');
        Workflow::apply($this->case(), 'assign', self::OFFICER, ['owner_id' => 7], self::NOW);
    }

    public function test_only_owner_can_accept_even_manager(): void
    {
        $this->expectRule('pegawai yang ditugaskan');
        Workflow::apply($this->case(['owner_id' => 7]), 'accept', self::MANAGER, [], self::NOW);
    }

    public function test_other_officer_cannot_act(): void
    {
        $this->expectRule('pegawai yang ditugaskan');
        Workflow::apply($this->case(['owner_id' => 7, 'status' => Workflow::IN_ACTION, 'accepted_at' => self::NOW]), 'action', self::OTHER, ['note' => 'cubaan tindakan'], self::NOW);
    }

    public function test_action_requires_acceptance(): void
    {
        $this->expectRule('Terima tugasan dahulu');
        Workflow::apply($this->case(['owner_id' => 7, 'status' => Workflow::SCREENING]), 'action', self::OFFICER, ['note' => 'cubaan tindakan'], self::NOW);
    }

    public function test_action_requires_note(): void
    {
        $this->expectRule('Catatan dalaman');
        Workflow::apply($this->case(['owner_id' => 7, 'status' => Workflow::IN_ACTION, 'accepted_at' => self::NOW]), 'action', self::OFFICER, ['note' => 'ok'], self::NOW);
    }

    public function test_closed_case_is_frozen(): void
    {
        $this->expectRule('ditutup');
        Workflow::apply($this->case(['status' => Workflow::CLOSED, 'owner_id' => 7]), 'publish', self::MANAGER, ['public_message' => 'Mesej baharu untuk awam'], self::NOW);
    }

    public function test_reassign_clears_acceptance(): void
    {
        $r = Workflow::apply($this->case(['status' => Workflow::IN_ACTION, 'owner_id' => 7, 'accepted_at' => self::NOW]), 'assign', self::MANAGER, ['owner_id' => 8], self::NOW);
        $this->assertNull($r['set']['accepted_at']);
        $this->assertSame(Workflow::IN_ACTION, $r['status'], 'Reassignment keeps the stage');
    }

    public function test_bad_priority_rejected(): void
    {
        $this->expectRule('keutamaan');
        Workflow::apply($this->case(), 'classify', self::MANAGER, ['priority' => 'TINGGI'], self::NOW);
    }

    public function test_bad_followup_date_rejected(): void
    {
        $this->expectRule('susulan');
        Workflow::apply($this->case(['owner_id' => 7, 'status' => Workflow::IN_ACTION, 'accepted_at' => self::NOW]), 'action', self::OFFICER, ['note' => 'Tindakan susulan', 'followup_at' => '25/10/2026'], self::NOW);
    }

    public function test_comment_does_not_change_status(): void
    {
        $r = Workflow::apply($this->case(['owner_id' => 7]), 'comment', self::OFFICER, ['note' => 'Pengadu telefon semula'], self::NOW);
        $this->assertSame(Workflow::RECEIVED, $r['status']);
        $this->assertSame([], $r['set']);
    }

    public function test_unknown_operation(): void
    {
        $this->expectRule('tidak sah');
        Workflow::apply($this->case(), 'delete', self::MANAGER, [], self::NOW);
    }
}
