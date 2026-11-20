<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-07f-2 (D-26): a system type may get a flow around its core action. The core action records
 * the built-in transaction with what its form asked at the start, under the run's number and
 * with the core rules; without a published flow the type records at once, as before.
 */
class SystemTypeFlowTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $approver;

    /** @var array<string, mixed> */
    private array $masters;

    /** @var array<string, mixed> a second location to move to */
    private array $otherMasters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->approver = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->masters = $this->createMasterFixtures($this->org);
        $this->otherMasters = $this->createMasterFixtures($this->org, '2');
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_a_system_flow_needs_exactly_one_core_action_that_no_completed_path_skips(): void
    {
        $loan = $this->systemType(TransactionType::LOAN);

        // Without a core action.
        $this->putJson("/api/transaction-types/{$loan}/flow", ['graph' => $this->graph(core: false)])->assertOk();
        $this->assertSame(['CORE_COUNT'], $this->issueCodes($this->postJson("/api/transaction-types/{$loan}/flow/publish"), 'CORE'));

        // A way around it: rejected goes to a completed end.
        $graph = $this->graph();
        $graph['nodes'][] = $this->node('lewat', 'end', 'Selesai tanpa inti', ['result' => 'completed']);
        $graph['edges'] = array_map(fn ($e) => $e['outcome'] === 'rejected' ? [...$e, 'target' => 'lewat'] : $e, $graph['edges']);
        $graph['nodes'] = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] !== 'tolak'));
        $this->putJson("/api/transaction-types/{$loan}/flow", ['graph' => $graph])->assertOk();
        $this->assertSame(['CORE_BYPASSED'], $this->issueCodes($this->postJson("/api/transaction-types/{$loan}/flow/publish"), 'CORE'));

        // The core action stands alone in its step.
        $graph = $this->graph();
        $graph['nodes'] = array_map(fn ($n) => $n['id'] === 'inti' ? [...$n, 'config' => ['actions' => [['kind' => 'core'], ['kind' => 'set_status', 'status' => 'in_use']]]] : $n, $graph['nodes']);
        $issues = $this->postJson("/api/transaction-types/{$loan}/flow/validate", ['graph' => $graph])->assertOk()->json('data.issues');
        $this->assertSame([['CONFIG', 'inti']], array_map(fn ($i) => [$i['code'], $i['node_id']], $issues));

        // An organization type has no core action.
        $own = $this->postJson('/api/transaction-types', ['code' => 'KAL', 'name' => 'Kalibrasi', 'status' => 'active', 'fields' => []])->assertCreated()->json('data.id');
        $issues = $this->postJson("/api/transaction-types/{$own}/flow/validate", ['graph' => $this->graph()])->assertOk()->json('data.issues');
        $this->assertSame(['CONFIG'], array_column($issues, 'code'));
        $this->assertStringContainsString('hanya ada pada jenis sistem', $issues[0]['message']);

        $this->publishFlow($loan);
    }

    public function test_a_loan_with_a_flow_waits_for_approval_then_records_the_built_in_loan(): void
    {
        $loan = $this->systemType(TransactionType::LOAN);
        $this->publishFlow($loan);
        $asset = $this->asset();

        $run = $this->postJson('/api/transactions', [
            'asset_id' => $asset, 'type' => 'loan', 'borrower_name' => 'Budi', 'due_date' => now()->addWeek()->toDateString(), 'notes' => 'Proyek A',
        ])->assertCreated()->json('data');
        $this->assertSame(['running', 'setuju', 'loan'], [$run['state'], $run['current_step']['id'], $run['type_definition']['system_type']]);
        $this->assertStringStartsWith('PJM-', $run['transaction_number']);
        $this->assertSame('Budi', $run['system_values']['borrower_name']);
        // Nothing changed yet, and the asset is held by the run (D-29).
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'transfer', 'to_location_id' => $this->otherLocation()]), 409, 'ASSET_LOCKED');

        $this->actingAs($this->approver, 'web');
        $done = $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'approved'])->assertOk()->json('data');
        $this->assertSame('completed', $done['state']);

        // The built-in loan, under the run's number, linked to the run; no 'custom' row.
        $this->actingAs($this->admin, 'web');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'on_loan')->assertJsonPath('data.borrower_name', 'Budi');
        $rows = $this->rows($asset);
        $this->assertSame([['registration', null], ['loan', $run['id']]], array_map(fn ($r) => [$r->type, $r->run_id], $rows));
        $this->assertSame([$run['transaction_number'], 'Budi', 'Proyek A'], [$rows[1]->transaction_number, $rows[1]->borrower_name, $rows[1]->notes]);
    }

    public function test_the_core_rules_and_permission_are_checked_when_the_run_starts(): void
    {
        $loan = $this->systemType(TransactionType::LOAN);
        $this->publishFlow($loan);
        $asset = $this->asset();

        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'loan'])
            ->assertStatus(422)->assertJsonValidationErrors(['borrower_name'], 'error.details.fields');

        // A user who may record transactions but not lend assets cannot start a loan.
        $this->createRole($this->org, 'PENCATAT', ['asset.view', 'transaction.view', 'transaction.create']);
        $this->actingAs($this->createTenantUser($this->org, ['PENCATAT']), 'web');
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'loan', 'borrower_name' => 'Budi']), 403, 'FORBIDDEN');
        $this->actingAs($this->admin, 'web');

        // An asset under maintenance cannot be lent: refused now, not after the approval.
        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'status_change', 'to_status' => 'under_maintenance'])->assertCreated();
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'loan', 'borrower_name' => 'Budi']), 409, 'INVALID_ASSET_STATUS');
        $this->assertSame(0, $this->runCount());
    }

    public function test_a_rejected_run_changes_nothing(): void
    {
        $status = $this->systemType(TransactionType::STATUS_CHANGE);
        $this->publishFlow($status);
        $asset = $this->asset();

        $run = $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'status_change', 'to_status' => 'under_maintenance'])->assertCreated()->json('data');
        $this->actingAs($this->approver, 'web');
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'rejected', 'note' => 'Belum perlu'])
            ->assertOk()->assertJsonPath('data.state', 'rejected');

        $this->actingAs($this->admin, 'web');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');
        $this->assertSame(['registration'], array_column($this->rows($asset), 'type'));
        // The asset is free again, and the type still records through its flow.
        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'status_change', 'to_status' => 'in_use'])
            ->assertCreated()->assertJsonPath('data.state', 'running');
    }

    public function test_fields_added_to_a_system_type_are_asked_and_kept_on_the_built_in_rows(): void
    {
        $transfer = $this->systemType(TransactionType::TRANSFER);
        $this->putJson("/api/transaction-types/{$transfer}", ['fields' => [
            ['key' => 'no_bast', 'label' => 'No. BAST', 'data_type' => 'text', 'is_required' => true],
        ]])->assertOk()->assertJsonPath('data.fields.0.key', 'no_bast')->assertJsonPath('data.name', TransactionType::LABELS[TransactionType::TRANSFER]);
        $asset = $this->asset();
        $to = $this->otherLocation();

        // Without a flow: recorded at once, the field is asked and kept.
        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'transfer', 'to_location_id' => $to])
            ->assertStatus(422)->assertJsonValidationErrors(['field_values.no_bast'], 'error.details.fields');
        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'transfer', 'to_location_id' => $to, 'field_values' => ['no_bast' => 'BAST-1']])
            ->assertCreated()->assertJsonPath('data.type', 'transfer');
        $this->assertSame(['no_bast' => 'BAST-1'], json_decode($this->rows($asset)[1]->field_values, true));

        // With a flow: the core action keeps the values on the row too.
        $this->publishFlow($transfer);
        $run = $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'transfer', 'to_location_id' => $this->masters['location']->id, 'field_values' => ['no_bast' => 'BAST-2']])
            ->assertCreated()->json('data');
        $this->actingAs($this->approver, 'web');
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'approved'])->assertOk();
        $last = $this->rows($asset)[2];
        $this->assertSame(['transfer', $run['id'], ['no_bast' => 'BAST-2']], [$last->type, $last->run_id, json_decode($last->field_values, true)]);
    }

    public function test_an_incoming_recorded_at_once_closes_an_outgoing_run_that_waits_for_it(): void
    {
        $outgoing = $this->systemType(TransactionType::OUTGOING);
        $this->publishFlow($outgoing, ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('inti', 'action', 'Catat keluar', ['actions' => [['kind' => 'core']]]),
            $this->node('tunggu', 'wait', 'Tunggu kembali', ['until' => 'paired']),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
        ], 'edges' => [$this->edge('start', 'inti'), $this->edge('inti', 'tunggu'), $this->edge('tunggu', 'ok')]]);
        $asset = $this->asset();

        $run = $this->postJson('/api/transactions', [
            'asset_id' => $asset, 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'Servis Jaya', 'due_date' => now()->addWeek()->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertSame(['running', 'tunggu'], [$run['state'], $run['current_step']['id']]);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'off_site');

        // The incoming is the pair the run waits for: it may take the held asset, and the run ends.
        $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'incoming'])->assertCreated();
        $this->getJson("/api/transaction-runs/{$run['id']}")->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');
        $this->assertSame(['registration', 'outgoing', 'incoming'], array_column($this->rows($asset), 'type'));
    }

    /** Start → approval → core action → completed; rejected → rejected end. */
    private function graph(bool $core = true): array
    {
        return ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('setuju', 'approval', 'Persetujuan', ['approver' => ['type' => 'user', 'id' => $this->approver->id]]),
            $this->node('inti', 'action', 'Catat', ['actions' => [$core ? ['kind' => 'core'] : ['kind' => 'set_status', 'status' => 'in_use']]]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $this->edge('start', 'setuju'), $this->edge('setuju', 'inti', 'approved'), $this->edge('setuju', 'tolak', 'rejected'), $this->edge('inti', 'ok'),
        ]];
    }

    private function node(string $id, string $type, string $label, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $source, string $target, ?string $outcome = null): array
    {
        return ['id' => "{$source}-{$target}", 'source' => $source, 'target' => $target, 'outcome' => $outcome];
    }

    private function publishFlow(string $typeId, ?array $graph = null): void
    {
        $this->putJson("/api/transaction-types/{$typeId}/flow", ['graph' => $graph ?? $this->graph()])->assertOk();
        $this->postJson("/api/transaction-types/{$typeId}/flow/publish")->assertOk()->assertJsonPath('data.status', 'published');
    }

    /** @return list<string> codes of the core issues a refused publish names */
    private function issueCodes($response, string $prefix): array
    {
        $this->assertErrorCode($response, 422, 'FLOW_INVALID');

        return array_values(array_filter(array_column($response->json('error.details.issues'), 'code'), fn ($c) => str_starts_with($c, $prefix)));
    }

    private function systemType(string $type): string
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => TransactionTypeDefinition::system($type)->id);
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    private function otherLocation(): string
    {
        return $this->otherMasters['location']->id;
    }

    /** @return list<object> the asset's history rows, oldest first */
    private function rows(string $assetId): array
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('asset_transactions')->where('asset_id', $assetId)->orderBy('seq')->get()->all());
    }

    private function runCount(): int
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => DB::table('transaction_runs')->count());
    }
}
