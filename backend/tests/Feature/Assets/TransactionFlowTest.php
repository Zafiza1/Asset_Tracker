<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transaction flows (F-07b): a type's published flow turns its transactions into runs that wait
 * at people's steps, apply asset changes at action steps under the run's number, lock the assets
 * meanwhile (D-29) and keep the flow version they started with (D-28).
 */
class TransactionFlowTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $approver;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->approver = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_a_run_walks_the_flow_locks_the_asset_and_applies_the_action(): void
    {
        $type = $this->type();
        $this->publishFlow($type['id']);
        $asset = $this->asset();

        $run = $this->start($type['id'], $asset)->assertCreated()->json('data');
        $this->assertSame(['running', 'isi'], [$run['state'], $run['current_step']['id']]);
        $this->assertSame(['input', 'user', $this->admin->id], [
            $run['open_task']['kind'], $run['open_task']['assignee_type'], $run['open_task']['assignee_id'],
        ]);

        // The asset is held by the run: no other transaction touches it (D-29).
        $this->assertErrorCode($this->start($type['id'], $asset), 409, 'ASSET_LOCKED');
        $this->assertErrorCode($this->postJson('/api/transactions', [
            'asset_id' => $asset, 'type' => 'status_change', 'to_status' => 'in_use',
        ]), 409, 'ASSET_LOCKED');
        // Nor can it be deactivated, which would leave the run unable to finish its action step.
        $this->assertErrorCode($this->postJson("/api/assets/{$asset}/archive"), 409, 'ASSET_LOCKED');

        // The input step takes the step's fields; the starter fills them.
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", [
            'field_values' => ['catatan' => 'Hasil hitung: 1 rusak'],
        ])->assertOk()->assertJsonPath('data.current_step.id', 'setuju')
            ->assertJsonPath('data.open_task.kind', 'approval')
            ->assertJsonPath('data.open_task.assignee_id', $this->approver->id);

        // Only the assignee works the step, and an approval needs a decision.
        $approval = $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.open_task.id');
        $this->assertErrorCode($this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$approval}/complete", ['decision' => 'approved']),
            403, 'FORBIDDEN');
        $this->actingAs($this->approver, 'web');
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$approval}/complete", [])->assertStatus(422);
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$approval}/complete", ['decision' => 'rejected'])
            ->assertStatus(422)->assertJsonValidationErrors(['note'], 'error.details.fields');

        // Approved: the action changes the asset under the run's number, then the run completes.
        $done = $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$approval}/complete", ['decision' => 'approved'])
            ->assertOk()->json('data');
        $this->assertSame(['completed', 'ok'], [$done['state'], $done['current_step']['id']]);
        $this->assertNull($done['open_task']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        // The action step's status change and the completion row share the run's number.
        $action = $this->getJson("/api/transactions?asset_id={$asset}&type=status_change")->assertOk()->json('data');
        $this->assertCount(1, $action);
        $this->assertSame($run['transaction_number'], $action[0]['transaction_number']);
        $this->getJson("/api/transactions?asset_id={$asset}&type=custom")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.transaction_number', $run['transaction_number']);
    }

    public function test_a_rejected_run_ends_without_touching_the_asset(): void
    {
        $type = $this->type();
        $this->publishFlow($type['id']);
        $asset = $this->asset();
        $run = $this->start($type['id'], $asset)->json('data');

        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", [
            'field_values' => ['catatan' => 'Cek'],
        ])->assertOk();
        $approval = $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.open_task.id');

        $this->actingAs($this->approver, 'web');
        $rejected = $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$approval}/complete", [
            'decision' => 'rejected', 'note' => 'Fisik tidak cocok',
        ])->assertOk()->json('data');

        $this->assertSame(['rejected', 'tolak'], [$rejected['state'], $rejected['current_step']['id']]);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');
        // Only the asset's registration row exists; the rejected run wrote nothing.
        $this->getJson("/api/transactions?asset_id={$asset}&type=custom")->assertOk()->assertJsonCount(0, 'data');
        // The asset is free again.
        $this->start($type['id'], $asset)->assertCreated();
    }

    public function test_the_creator_cancels_before_any_effect(): void
    {
        $type = $this->type();
        $this->publishFlow($type['id']);
        $asset = $this->asset();
        $run = $this->start($type['id'], $asset)->json('data');

        $this->postJson("/api/transaction-runs/{$run['id']}/cancel", ['note' => 'Salah input'])->assertOk()
            ->assertJsonPath('data.state', 'cancelled');

        // After the action step changed the asset, cancelling is refused.
        $run2 = $this->start($type['id'], $asset)->json('data');
        $this->postJson("/api/transaction-runs/{$run2['id']}/tasks/{$run2['open_task']['id']}/complete", ['field_values' => ['catatan' => 'Cek']])->assertOk();
        $approval = $this->getJson("/api/transaction-runs/{$run2['id']}")->json('data.open_task.id');
        $this->actingAs($this->approver, 'web');
        $this->postJson("/api/transaction-runs/{$run2['id']}/tasks/{$approval}/complete", ['decision' => 'approved'])->assertOk();
        $this->assertErrorCode($this->postJson("/api/transaction-runs/{$run2['id']}/cancel"), 409, 'RUN_ENDED');
    }

    public function test_a_run_that_already_changed_an_asset_cannot_be_cancelled(): void
    {
        $type = $this->type();
        $node = fn (string $id, string $type, ?string $label, array $config = []) => [
            'id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config,
        ];
        // Start → Aksi (status Dalam Perbaikan) → Isi data → Selesai: the change happens before people's step.
        $this->publishFlow($type['id'], ['nodes' => [
            $node('start', 'start', 'Mulai'),
            $node('aksi', 'action', 'Kirim perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $node('isi', 'input', 'Hasil', ['assignee' => ['type' => 'starter'], 'fields' => ['catatan'], 'required' => ['catatan']]),
            $node('ok', 'end', 'Selesai', ['result' => 'completed']),
        ], 'edges' => [
            ['id' => 'e1', 'source' => 'start', 'target' => 'aksi', 'outcome' => null],
            ['id' => 'e2', 'source' => 'aksi', 'target' => 'isi', 'outcome' => null],
            ['id' => 'e3', 'source' => 'isi', 'target' => 'ok', 'outcome' => null],
        ]]);
        $asset = $this->asset();

        $run = $this->start($type['id'], $asset)->assertCreated()->json('data');
        $this->assertSame(['running', 'isi'], [$run['state'], $run['current_step']['id']]);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        $this->assertErrorCode($this->postJson("/api/transaction-runs/{$run['id']}/cancel"), 409, 'RUN_HAS_CHANGES');
        $this->assertSame('running', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
    }

    public function test_only_managers_draw_publish_and_test_the_flow(): void
    {
        $type = $this->type();
        $viewer = $this->createTenantUser($this->org, [$this->viewerRole($this->org)]);
        $this->actingAs($viewer, 'web');

        $this->getJson("/api/transaction-types/{$type['id']}/flow")->assertOk()->assertJsonPath('data.draft', null);
        $this->assertErrorCode($this->putJson("/api/transaction-types/{$type['id']}/flow", ['graph' => $this->graph()]), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/transaction-types/{$type['id']}/flow/publish"), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/transaction-types/{$type['id']}/flow/simulate", ['asset_ids' => [(string) Str::uuid()]]), 403, 'FORBIDDEN');

        // The dry run shows the path without saving anything.
        $this->actingAs($this->admin, 'web');
        $this->putJson("/api/transaction-types/{$type['id']}/flow", ['graph' => $this->graph()])->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $check = $this->postJson("/api/transaction-types/{$type['id']}/flow/validate")->assertOk()->json('data');
        $this->assertSame([true, []], [$check['valid'], $check['issues']]);
        $path = $this->postJson("/api/transaction-types/{$type['id']}/flow/simulate", [
            'asset_ids' => [$this->asset()], 'decisions' => ['setuju' => 'approved'],
        ])->assertOk()->json('data.path');
        $this->assertSame(['start', 'isi', 'setuju', 'aksi', 'ok'], array_column($path, 'node_id'));
    }

    public function test_another_organization_sees_neither_the_flow_nor_the_runs(): void
    {
        $type = $this->type();
        $this->publishFlow($type['id']);
        $asset = $this->asset();
        $run = $this->start($type['id'], $asset)->json('data');

        ['organization' => $beta, 'admin' => $betaAdmin] = $this->createOrganization('BETA');
        $this->createMasterFixtures($beta, '2');
        $this->actingAs($betaAdmin, 'web');

        $this->assertSame(404, $this->getJson("/api/transaction-types/{$type['id']}/flow")->status());
        $this->assertSame(404, $this->getJson("/api/transaction-runs/{$run['id']}")->status());
        $this->getJson('/api/transaction-runs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_condition_sends_the_run_down_the_matching_branch(): void
    {
        $type = $this->type();
        $this->publishFlow($type['id'], $this->graphWithCondition());
        $asset = $this->asset();

        // catatan = 'rusak' takes the yes branch: status change, then completed.
        $run = $this->start($type['id'], $asset)->json('data');
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", [
            'field_values' => ['catatan' => 'rusak'],
        ])->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        // Anything else takes the no branch straight to the rejected end.
        $asset2 = $this->asset();
        $run2 = $this->start($type['id'], $asset2)->json('data');
        $this->postJson("/api/transaction-runs/{$run2['id']}/tasks/{$run2['open_task']['id']}/complete", [
            'field_values' => ['catatan' => 'baik'],
        ])->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->getJson("/api/assets/{$asset2}")->assertJsonPath('data.status', 'available');
    }

    public function test_a_wait_step_holds_the_run_until_its_date_then_resumes(): void
    {
        $type = $this->postJson('/api/transaction-types', [
            'code' => 'tunggu', 'name' => 'Pinjam dengan kembali', 'status' => 'active',
            'category_ids' => [$this->masters['category']->id],
            'fields' => [['key' => 'kembali', 'label' => 'Tanggal kembali', 'data_type' => 'date', 'is_required' => true]],
        ])->assertCreated()->json('data');

        $node = fn (string $id, string $type, ?string $label, array $config = []) => [
            'id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config,
        ];
        $graph = ['nodes' => [
            $node('start', 'start', 'Mulai'),
            $node('tunggu', 'wait', 'Tunggu tanggal kembali', ['until' => 'date', 'field' => 'kembali']),
            $node('ok', 'end', 'Selesai', ['result' => 'completed']),
        ], 'edges' => [
            ['id' => 'e1', 'source' => 'start', 'target' => 'tunggu', 'outcome' => null],
            ['id' => 'e2', 'source' => 'tunggu', 'target' => 'ok', 'outcome' => null],
        ]];
        $this->putJson("/api/transaction-types/{$type['id']}/flow", ['graph' => $graph])->assertOk();
        $this->postJson("/api/transaction-types/{$type['id']}/flow/publish")->assertOk();

        $asset = $this->asset();
        $tomorrow = now()->addDay()->toDateString();
        $run = $this->postJson('/api/transactions', [
            'asset_id' => $asset, 'type' => 'custom', 'type_definition_id' => $type['id'],
            'field_values' => ['kembali' => $tomorrow],
        ])->assertCreated()->json('data');
        $this->assertSame(['running', 'tunggu'], [$run['state'], $run['current_step']['id']]);
        $this->assertNull($run['open_task']);

        // Before the date the scheduled resume leaves it waiting; after, it completes.
        Artisan::call('transactions:resume');
        $this->assertSame('running', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
        Carbon::setTestNow(now()->addDays(2));
        try {
            Artisan::call('transactions:resume');
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame('completed', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
    }

    /** Start → Isi data → Kondisi (catatan = rusak?) → Aksi / Ditolak. */
    private function graphWithCondition(): array
    {
        $node = fn (string $id, string $type, ?string $label, array $config = []) => [
            'id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config,
        ];
        $edge = fn (string $source, string $target, ?string $outcome = null) => [
            'id' => "{$source}-{$target}-{$outcome}", 'source' => $source, 'target' => $target, 'outcome' => $outcome,
        ];

        return ['nodes' => [
            $node('start', 'start', 'Mulai'),
            $node('isi', 'input', 'Hasil hitung', ['assignee' => ['type' => 'starter'], 'fields' => ['catatan'], 'required' => ['catatan']]),
            $node('cek', 'condition', 'Ada yang rusak?', ['rules' => [['subject' => 'field.catatan', 'op' => 'eq', 'value' => 'rusak']], 'match' => 'all', 'assets' => 'any']),
            $node('aksi', 'action', 'Kirim perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $node('tolak', 'end', 'Tidak ada rusak', ['result' => 'rejected']),
        ], 'edges' => [
            $edge('start', 'isi'), $edge('isi', 'cek'),
            $edge('cek', 'aksi', 'yes'), $edge('cek', 'tolak', 'no'),
            $edge('aksi', 'ok'),
        ]];
    }

    /** @return array<string, mixed> */
    private function type(): array
    {
        return $this->postJson('/api/transaction-types', [
            'code' => 'opn', 'name' => 'Stock Opname', 'status' => 'active',
            'category_ids' => [$this->masters['category']->id],
            'fields' => [
                ['key' => 'catatan', 'label' => 'Catatan opname', 'data_type' => 'text'],
            ],
        ])->assertCreated()->json('data');
    }

    /** Start → Isi data → Persetujuan → Aksi (status Dalam Perbaikan) → Selesai / Ditolak. */
    private function graph(): array
    {
        $node = fn (string $id, string $type, ?string $label, array $config = []) => [
            'id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config,
        ];
        $edge = fn (string $source, string $target, ?string $outcome = null) => [
            'id' => "{$source}-{$target}-{$outcome}", 'source' => $source, 'target' => $target, 'outcome' => $outcome,
        ];

        return ['nodes' => [
            $node('start', 'start', 'Mulai'),
            $node('isi', 'input', 'Hasil hitung', ['assignee' => ['type' => 'starter'], 'fields' => ['catatan'], 'required' => ['catatan']]),
            $node('setuju', 'approval', 'Persetujuan', ['approver' => ['type' => 'user', 'id' => $this->approver->id]]),
            $node('aksi', 'action', 'Kirim perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $edge('start', 'isi'), $edge('isi', 'setuju'),
            $edge('setuju', 'aksi', 'approved'), $edge('setuju', 'tolak', 'rejected'),
            $edge('aksi', 'ok'),
        ]];
    }

    private function publishFlow(string $typeId, ?array $graph = null): void
    {
        $this->putJson("/api/transaction-types/{$typeId}/flow", ['graph' => $graph ?? $this->graph()])->assertOk();
        $this->postJson("/api/transaction-types/{$typeId}/flow/publish")->assertOk()->assertJsonPath('data.status', 'published');
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    private function start(string $typeId, string $assetId)
    {
        return $this->postJson('/api/transactions', [
            'asset_id' => $assetId, 'type' => 'custom', 'type_definition_id' => $typeId, 'field_values' => [],
        ]);
    }
}
