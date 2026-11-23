<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Flows\RuleCompiler;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-08g (D-46): named rules are kept with the flow version and compiled into flow steps the
 * F-07b engine runs: Tolak → a refusal at the start, Perlu persetujuan → Kondisi + Persetujuan
 * after the flow's own approvals, Lewati persetujuan → a Kondisi in front of that approval.
 */
class TransactionRulesTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** Records; the admin approves (no self-approval). */
    private User $recorder;

    /** @var array<string, mixed> */
    private array $masters;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $this->actingAs($this->admin, 'web');
        $this->type = $this->postJson('/api/transaction-types', [
            'code' => 'PRB', 'name' => 'Perbaikan', 'status' => 'active', 'fields' => [
                ['key' => 'estimasi', 'label' => 'Estimasi biaya', 'data_type' => 'money', 'is_required' => false],
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_a_reject_rule_refuses_the_transaction_with_its_message(): void
    {
        $rule = $this->rule('mahal', 'Larangan biaya berlebihan', 1, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 100_000_000]],
            ['kind' => 'reject', 'message' => 'Biaya terlalu besar; ajukan penghapusan.']);
        $draft = $this->save([$rule])->assertOk()->assertJsonPath('data.graph_source', 'rules')->assertJsonPath('data.issues', [])->json('data');
        $start = collect($draft['graph']['nodes'])->firstWhere('type', 'start');
        $this->assertSame('mahal', $start['config']['rejections'][0]['rule_id']);
        $this->assertEquals($this->base(), $draft['base_graph']);
        $this->publish();

        $this->assertErrorCode($response = $this->start($this->asset(1_000_000), 200_000_000), 422, 'PRECONDITION_FAILED');
        $this->assertSame('Biaya terlalu besar; ajukan penghapusan.', $response->json('error.message'));
        $this->start($this->asset(1_000_000), 1_000_000)->assertCreated();

        // The dry run stops at the start with the message the recorder would see.
        $path = $this->postJson("/api/transaction-types/{$this->type}/flow/simulate", ['asset_ids' => [$this->asset(1)], 'field_values' => ['estimasi' => 200_000_000]])
            ->assertOk()->json('data.path');
        $this->assertSame(['blocked', 'Biaya terlalu besar; ajukan penghapusan.', 'mahal'], [$path[0]['outcome'], $path[0]['message'], $path[0]['rule_id']]);
    }

    public function test_approval_rules_follow_the_flows_own_approvals_in_priority_order(): void
    {
        $superAdmin = $this->roleId($this->org, RoleTemplates::SUPER_ADMIN);
        $supervisor = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR]);
        $rules = [
            $this->rule('selalu', 'Persetujuan Supervisor', 2, [], ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $this->roleId($this->org, RoleTemplates::SUPERVISOR)]]),
            $this->rule('nilai', 'Approval aset bernilai tinggi', 1, [['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10_000_000]],
                ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $superAdmin], 'min_approvals' => 1]),
        ];
        $this->save($rules)->assertOk()->assertJsonPath('data.issues', []);
        $this->publish();

        // Costly: Supervisor lokasi (the flow's own), then the rules by priority, then the action.
        $asset = $this->asset(15_000_000);
        $run = $this->start($asset)->assertCreated()->json('data');
        $this->assertSame('setuju', $run['current_step']['id']);
        $run = $this->complete($run, 'approved')->json('data');
        $this->assertSame('ra_nilai', $run['current_step']['id']);
        $run = $this->complete($run, 'approved')->json('data');
        $this->assertSame('ra_selalu', $run['current_step']['id']);
        $this->complete($run, 'approved', $supervisor)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        // Cheap: the conditional rule does not apply; the rule that always applies does.
        $run = $this->complete($this->start($this->asset(1_000_000))->json('data'), 'approved')->json('data');
        $this->assertSame('ra_selalu', $run['current_step']['id']);
        // Refused at a rule step: the run ends as Ditolak and the asset is unchanged.
        $this->complete($run, 'rejected', $supervisor)->assertOk()->assertJsonPath('data.state', 'rejected');

        // Swapping the priorities swaps the steps.
        $rules[0]['priority'] = 1;
        $rules[1]['priority'] = 2;
        $graph = $this->save($rules)->json('data.graph');
        $this->assertSame('rc_nilai', $this->target($graph, 'ra_selalu', 'approved'));
    }

    public function test_a_skip_rule_goes_past_the_named_approval(): void
    {
        $this->save([$this->rule('murah', 'Biaya kecil tanpa persetujuan', 1, [['subject' => 'field.estimasi', 'op' => 'lt', 'value' => 500_000]],
            ['kind' => 'skip_approval', 'step' => 'setuju'])])->assertOk()->assertJsonPath('data.issues', []);
        $this->publish();

        $this->start($this->asset(1_000_000), 100_000)->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->start($this->asset(1_000_000), 900_000)->assertCreated()->assertJsonPath('data.current_step.id', 'setuju');

        // A skip names an approval step of the flow.
        $issues = $this->save([$this->rule('murah', 'Biaya kecil', 1, [['subject' => 'field.estimasi', 'op' => 'lt', 'value' => 1]],
            ['kind' => 'skip_approval', 'step' => 'aksi'])])->json('data.issues');
        $this->assertSame(['RULE', 'murah'], [$issues[0]['code'], $issues[0]['rule_id']]);
    }

    public function test_inactive_and_overruled_rules_do_not_apply(): void
    {
        $when = [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 50_000_000]];
        $rules = [
            [...$this->rule('mati', 'Larangan lama', 1, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 1]], ['kind' => 'reject', 'message' => 'Tidak boleh.']), 'active' => false],
            $this->rule('setuju', 'Biaya besar perlu Super Admin', 2, $when, ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)]]),
            $this->rule('tolak', 'Biaya besar ditolak', 3, $when, ['kind' => 'reject', 'message' => 'Terlalu mahal.']),
        ];
        $this->assertSame(['tolak' => 'setuju'], RuleCompiler::overruled($rules));
        $graph = $this->save($rules)->assertOk()->json('data.graph');
        $this->assertArrayNotHasKey('rejections', collect($graph['nodes'])->firstWhere('type', 'start')['config']);
        $this->assertNotNull(collect($graph['nodes'])->firstWhere('id', 'ra_setuju'));

        $described = collect($this->getJson("/api/transaction-types/{$this->type}/flow/describe")->assertOk()->json('data.rules'))->keyBy('id');
        $this->assertSame([false, false, true], [$described['mati']['applies'], $described['tolak']['applies'], $described['setuju']['applies']]);
        $this->assertStringContainsString('Biaya besar perlu Super Admin', $described['tolak']['note']);
        $this->assertSame(['mati', 'setuju', 'tolak'], $described->keys()->all(), 'listed by priority');
    }

    public function test_compiling_again_keeps_the_users_steps_and_the_canvas_keeps_the_rules(): void
    {
        $rules = [
            $this->rule('nilai', 'Approval nilai tinggi', 1, [['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10_000_000]],
                ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)]]),
            $this->rule('murah', 'Lewati untuk biaya kecil', 2, [['subject' => 'field.estimasi', 'op' => 'lt', 'value' => 100]], ['kind' => 'skip_approval', 'step' => 'setuju']),
            $this->rule('mahal', 'Tolak biaya besar', 3, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 1_000_000_000]], ['kind' => 'reject', 'message' => 'Tidak.']),
        ];
        $first = $this->save($rules)->json('data');
        // Sending the compiled graph back gives the same flow: old rule steps are replaced, not added to.
        $again = $this->save($rules, $first['graph'])->json('data');
        $this->assertEquals($first['graph'], $again['graph']);
        $this->assertEquals($this->base(), $again['base_graph']);
        $this->assertEquals($this->base(), RuleCompiler::compile($this->base(), []), 'no rules, no change');

        // Changed on the canvas (no rules sent): the graph stays as drawn, the rules stay to read.
        $canvas = $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $first['graph']])->assertOk()
            ->assertJsonPath('data.graph_source', 'canvas')->json('data');
        $this->assertSame(['nilai', 'murah', 'mahal'], array_column($canvas['rules'], 'id'));
        $this->assertEquals($first['graph'], $canvas['graph']);
        $this->publish();
    }

    public function test_rule_problems_block_publishing(): void
    {
        $draft = $this->save([
            $this->rule('pesan', 'Tolak tanpa pesan', 1, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 1]], ['kind' => 'reject', 'message' => '']),
            $this->rule('data', 'Data tak dikenal', 2, [['subject' => 'field.tidak_ada', 'op' => 'eq', 'value' => 1]], ['kind' => 'reject', 'message' => 'X']),
            $this->rule('kosong', 'Tolak tanpa kondisi', 3, [], ['kind' => 'reject', 'message' => 'Y']),
            $this->rule('siapa', 'Tanpa penyetuju', 4, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 5]], ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => null]]),
        ])->assertOk()->json('data');
        $byRule = collect($draft['issues'])->groupBy('rule_id');
        $this->assertTrue($byRule->has('pesan') && $byRule->has('data') && $byRule->has('kosong'));
        $this->assertContains('ra_siapa', array_column($draft['issues'], 'node_id'), 'the compiled approval is checked like any other');

        $this->assertErrorCode($this->postJson("/api/transaction-types/{$this->type}/flow/publish"), 422, 'FLOW_INVALID');
        $this->postJson("/api/transaction-types/{$this->type}/flow/validate")->assertOk()->assertJsonPath('data.valid', false);
        $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $this->base(), 'rules' => [['id' => 'BAD!', 'name' => 'x']]])->assertStatus(422);
    }

    public function test_documentation_tells_the_rules_and_what_changed_between_versions(): void
    {
        $superAdmin = $this->roleId($this->org, RoleTemplates::SUPER_ADMIN);
        $nilai = $this->rule('nilai', 'Approval aset bernilai tinggi', 1, [['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10_000_000]],
            ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $superAdmin]]);
        $this->save([$nilai]);
        $this->publish();

        $tolak = $this->rule('tolak', 'Larangan biaya besar', 2, [['subject' => 'field.estimasi', 'op' => 'gt', 'value' => 100_000_000]],
            ['kind' => 'reject', 'message' => 'Ajukan penghapusan.']);
        $this->save([[...$nilai, 'active' => false], $tolak]);
        $doc = $this->getJson("/api/transaction-types/{$this->type}/flow/describe")->assertOk()->json('data');

        $this->assertSame(1, $doc['previous_version']);
        $this->assertSame('Bila nilai perolehan salah satu aset lebih dari Rp 10.000.000, perlu disetujui pemegang role Super Admin.', $doc['rules'][0]['sentence']);
        $this->assertStringContainsString('Transaksi tidak dapat dicatat bila Estimasi biaya lebih dari Rp 100.000.000. Pesan: "Ajukan penghapusan."', $doc['preconditions']);
        $changes = implode("\n", $doc['changes']);
        $this->assertStringContainsString('Rule "Approval aset bernilai tinggi" dinonaktifkan.', $changes);
        $this->assertStringContainsString('Rule "Larangan biaya besar" ditambahkan: Bila Estimasi biaya lebih dari Rp 100.000.000, transaksi tidak dapat dicatat.', $changes);
        // Rule steps are told as rules, not again as added or removed steps.
        $this->assertStringNotContainsString('Langkah "Approval aset bernilai tinggi"', $changes);
    }

    public function test_a_running_transaction_keeps_the_rules_of_its_version_and_a_copy_keeps_the_rules(): void
    {
        $this->save([$this->rule('nilai', 'Approval nilai tinggi', 1, [['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10_000_000]],
            ['kind' => 'approval', 'approver' => ['type' => 'role', 'id' => $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)]])]);
        $this->publish();
        $run = $this->start($this->asset(15_000_000))->json('data');

        $this->save([]);
        $this->publish();
        $run = $this->complete($run, 'approved')->json('data');
        $this->assertSame('ra_nilai', $run['current_step']['id'], 'version 1 still has the rule step');
        $this->complete($this->start($this->asset(15_000_000))->json('data'), 'approved')->assertJsonPath('data.state', 'completed');

        $copy = $this->postJson("/api/transaction-types/{$this->type}/duplicate", ['code' => 'PRBB', 'name' => 'Perbaikan B'])->assertCreated()->json('data.id');
        $this->getJson("/api/transaction-types/{$copy}/flow")->assertOk()->assertJsonPath('data.draft.graph_source', 'rules')->assertJsonPath('data.draft.rules', []);
    }

    /** Mulai → Persetujuan Supervisor lokasi → Dalam perbaikan → Selesai (Ditolak on refusal). */
    private function base(): array
    {
        $node = fn (string $id, string $type, string $label, array $config = []) => ['id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
        $edge = fn (string $s, string $t, ?string $o = null) => ['id' => "{$s}-{$t}", 'source' => $s, 'target' => $t, 'outcome' => $o];

        return ['nodes' => [
            $node('start', 'start', 'Diajukan'),
            $node('setuju', 'approval', 'Persetujuan Supervisor lokasi', ['approver' => ['type' => 'location_supervisor'], 'min_approvals' => 1]),
            $node('aksi', 'action', 'Dalam perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $edge('start', 'setuju'), $edge('setuju', 'aksi', 'approved'), $edge('setuju', 'tolak', 'rejected'), $edge('aksi', 'ok'),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function rule(string $id, string $name, int $priority, array $conditions, array $outcome): array
    {
        return ['id' => $id, 'name' => $name, 'description' => null, 'priority' => $priority, 'active' => true,
            'match' => 'all', 'assets' => 'any', 'conditions' => $conditions, 'outcome' => $outcome];
    }

    /** @param list<array<string, mixed>> $rules */
    private function save(array $rules, ?array $graph = null): TestResponse
    {
        return $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $graph ?? $this->base(), 'rules' => $rules]);
    }

    private function publish(): void
    {
        $this->postJson("/api/transaction-types/{$this->type}/flow/publish")->assertOk();
    }

    private function target(array $graph, string $source, ?string $outcome): ?string
    {
        return collect($graph['edges'])->first(fn ($e) => $e['source'] === $source && $e['outcome'] === $outcome)['target'] ?? null;
    }

    private function start(string $assetId, ?int $estimate = null): TestResponse
    {
        $this->actingAs($this->recorder, 'web');
        $response = $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => 'custom', 'type_definition_id' => $this->type,
            'field_values' => $estimate === null ? [] : ['estimasi' => $estimate]]);
        $this->actingAs($this->admin, 'web');

        return $response;
    }

    /** @param array<string, mixed> $run */
    private function complete(array $run, string $decision, ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->admin, 'web');
        $response = $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete",
            ['decision' => $decision, 'note' => $decision === 'rejected' ? 'Tidak disetujui.' : null]);
        $this->actingAs($this->admin, 'web');

        return $response;
    }

    private function asset(int $cost): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Alat '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available', 'acquisition_cost' => $cost,
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }
}
