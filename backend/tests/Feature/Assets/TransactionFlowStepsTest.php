<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Notifications\TransactionFlowNotification;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Flow steps beyond the basics (F-07b): approvals needing several approvers or the location's
 * supervisors, the condition / loan / return / notification actions, waiting for the paired
 * transaction, attachment fields, and the home card of steps waiting for the user.
 */
class TransactionFlowStepsTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_an_approval_waits_for_the_required_number_of_different_approvers(): void
    {
        $first = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $second = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $type = $this->type();
        $this->publish($type['id'], $this->approvalGraph(['type' => 'role', 'id' => $this->roleId($this->org, 'SUPERVISOR')], 2));
        $run = $this->start($type['id'], $this->asset())->assertCreated()->json('data');

        // Both supervisors see the step on their home page.
        $this->actingAs($first, 'web');
        $card = collect($this->getJson('/api/home/cards')->assertOk()->json('data'))->firstWhere('key', 'decisions')['tasks'];
        $this->assertSame(1, $card['count']);
        $this->assertSame([$run['transaction_number'], 'approval', 'Persetujuan'], [$card['items'][0]['transaction_number'], $card['items'][0]['kind'], $card['items'][0]['step']]);

        $task = $run['open_task']['id'];
        $this->complete($run['id'], $task, ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('data.state', 'running')->assertJsonPath('data.open_task.approvals', 1)
            ->assertJsonPath('data.open_task.required_approvals', 2)->assertJsonPath('data.open_task.can_act', false);
        // The same person cannot count twice.
        $this->assertErrorCode($this->complete($run['id'], $task, ['decision' => 'approved']), 409, 'ALREADY_APPROVED');

        $this->actingAs($second, 'web');
        $this->complete($run['id'], $task, ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_a_location_supervisor_approval_goes_to_supervisors_whose_scope_covers_the_assets(): void
    {
        $other = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC9', 'name' => 'Gudang 9']);
        $other->save();
        $here = $this->createTenantUser($this->org, ['SUPERVISOR'], [['scope_type' => 'location', 'ref_id' => $this->masters['location']->id]]);
        $elsewhere = $this->createTenantUser($this->org, ['SUPERVISOR'], [['scope_type' => 'location', 'ref_id' => $other->id]]);
        $type = $this->type();
        $this->publish($type['id'], $this->approvalGraph(['type' => 'location_supervisor']));
        $run = $this->start($type['id'], $this->asset())->assertCreated()->json('data');
        $this->assertSame('location_supervisor', $run['open_task']['assignee_type']);

        // The starter is a supervisor of every location too, but may not approve their own request.
        $this->assertErrorCode($this->complete($run['id'], $run['open_task']['id'], ['decision' => 'approved']), 403, 'SELF_APPROVAL');

        $this->actingAs($elsewhere, 'web');
        $this->assertSame(404, $this->complete($run['id'], $run['open_task']['id'], ['decision' => 'approved'])->status());
        $this->getJson('/api/transaction-runs?assigned_to_me=1')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($here, 'web');
        $this->getJson('/api/transaction-runs?assigned_to_me=1')->assertOk()->assertJsonCount(1, 'data');
        $this->complete($run['id'], $run['open_task']['id'], ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_flow_settings_are_checked_before_publishing(): void
    {
        $type = $this->type();
        $user = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $bad = $this->approvalGraph(['type' => 'user', 'id' => $user->id], 2);
        $bad['nodes'][] = $this->node('tunggu', 'wait', 'Tunggu', ['until' => 'paired']);
        $bad['edges'] = [...array_filter($bad['edges'], fn ($e) => $e['source'] !== 'aksi'),
            $this->edge('aksi', 'tunggu'), $this->edge('tunggu', 'ok')];

        $this->putJson("/api/transaction-types/{$type['id']}/flow", ['graph' => $bad])->assertOk();
        $issues = $this->postJson("/api/transaction-types/{$type['id']}/flow/validate")->assertOk()->json('data.issues');
        $messages = implode(' | ', array_column($issues, 'message'));
        $this->assertStringContainsString('hanya dapat memberi satu persetujuan', $messages);
        $this->assertStringContainsString('belum ada jenis transaksi yang menutup jenis ini', $messages);
        $this->assertErrorCode($this->postJson("/api/transaction-types/{$type['id']}/flow/publish"), 422, 'FLOW_INVALID');
    }

    public function test_actions_set_the_condition_lend_return_and_notify(): void
    {
        Notification::fake();
        // The category gets the default fields, among them Kondisi Aset.
        app(CategoryAttributeService::class)->createDefaultAttributes($this->masters['category']);
        $this->relaxDefaultCondition();
        $holder = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $type = $this->postJson('/api/transaction-types', [
            'code' => 'srp', 'name' => 'Serah pakai', 'status' => 'active', 'category_ids' => [$this->masters['category']->id],
            'fields' => [
                ['key' => 'pemegang', 'label' => 'Pemegang', 'data_type' => 'user', 'is_required' => true],
                ['key' => 'kembali', 'label' => 'Rencana kembali', 'data_type' => 'date'],
            ],
        ])->assertCreated()->json('data');
        $this->publish($type['id'], ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('serah', 'action', 'Serahkan', ['actions' => [
                ['kind' => 'set_condition', 'value' => 'Rusak Ringan'],
                ['kind' => 'loan_to_field_user', 'user_field' => 'pemegang', 'due_field' => 'kembali'],
                ['kind' => 'notify', 'to' => ['type' => 'field', 'field' => 'pemegang'], 'message' => "Aset diserahkan kepada Anda.\nHarap dijaga."],
            ]]),
            $this->node('isi', 'input', 'Konfirmasi', ['assignee' => ['type' => 'field', 'field' => 'pemegang'], 'fields' => ['kembali']]),
            $this->node('balik', 'action', 'Kembalikan', ['actions' => [['kind' => 'return']]]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
        ], 'edges' => [$this->edge('start', 'serah'), $this->edge('serah', 'isi'), $this->edge('isi', 'balik'), $this->edge('balik', 'ok')]]);

        $asset = $this->asset();
        $due = now()->addDays(7)->toDateString();
        $run = $this->start($type['id'], $asset, ['pemegang' => $holder->id, 'kembali' => $due])->assertCreated()->json('data');

        $data = $this->getJson("/api/assets/{$asset}")->assertOk()->json('data');
        $this->assertSame(['on_loan', $holder->name, $due], [$data['status'], $data['borrower_name'], substr((string) $data['loan_due_date'], 0, 10)]);
        $conditionKey = app(CategoryAttributeService::class)->conditionAttribute($this->masters['category']->id)->key;
        $this->assertSame('RUSAK_RINGAN', $data['attributes'][$conditionKey]);
        Notification::assertSentTo($holder, TransactionFlowNotification::class, fn ($n) => $n->transactionNumber === $run['transaction_number']);
        // The condition change shows in the asset's history under the run's number.
        $this->getJson("/api/transactions?asset_id={$asset}&type=note")->assertOk()
            ->assertJsonPath('data.0.transaction_number', $run['transaction_number']);

        // The holder confirms; the asset comes back.
        $this->actingAs($holder, 'web');
        $this->complete($run['id'], $run['open_task']['id'], ['field_values' => ['kembali' => $due]])->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available')->assertJsonPath('data.borrower_name', null);
    }

    public function test_a_run_waits_for_its_paired_transaction_which_may_take_the_locked_asset(): void
    {
        $send = $this->postJson('/api/transaction-types', [
            'code' => 'kal', 'name' => 'Kirim kalibrasi', 'status' => 'active', 'category_ids' => [$this->masters['category']->id], 'fields' => [],
        ])->assertCreated()->json('data');
        $back = $this->postJson('/api/transaction-types', [
            'code' => 'kbl', 'name' => 'Kembali kalibrasi', 'status' => 'active', 'category_ids' => [$this->masters['category']->id],
            'closes_type_id' => $send['id'], 'fields' => [],
        ])->assertCreated()->json('data');
        $this->publish($send['id'], ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('kirim', 'action', 'Kirim', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $this->node('tunggu', 'wait', 'Tunggu kembali', ['until' => 'paired']),
            $this->node('terima', 'action', 'Terima', ['actions' => [['kind' => 'set_status', 'status' => 'available']]]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
        ], 'edges' => [$this->edge('start', 'kirim'), $this->edge('kirim', 'tunggu'), $this->edge('tunggu', 'terima'), $this->edge('terima', 'ok')]]);

        $asset = $this->asset();
        $run = $this->start($send['id'], $asset)->assertCreated()->json('data');
        $this->assertSame(['running', 'tunggu'], [$run['state'], $run['current_step']['id']]);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        // Other transactions stay out; the closing type may record on the held asset.
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'note', 'notes' => 'Cek']), 409, 'ASSET_LOCKED');
        $this->start($back['id'], $asset)->assertCreated();

        $this->assertSame('completed', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');
    }

    public function test_attachment_fields_take_an_uploaded_file_that_only_its_viewers_can_download(): void
    {
        Storage::fake(TransactionAttachments::DISK);
        $type = $this->postJson('/api/transaction-types', [
            'code' => 'bast', 'name' => 'Serah terima', 'status' => 'active', 'category_ids' => [$this->masters['category']->id],
            'fields' => [['key' => 'bast', 'label' => 'Berita acara', 'data_type' => 'attachment', 'is_required' => true,
                'settings' => ['accept' => ['pdf'], 'max_mb' => 1]]],
        ])->assertCreated()->json('data');
        $upload = fn (UploadedFile $file) => $this->post("/api/transaction-types/{$type['id']}/attachments", ['field_key' => 'bast', 'file' => $file], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->create('foto.png', 10, 'image/png'))->assertStatus(422)->assertJsonValidationErrors(['file'], 'error.details.fields');
        $upload(UploadedFile::fake()->create('besar.pdf', 2048, 'application/pdf'))->assertStatus(422);
        $file = $upload(UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'))->assertCreated()->json('data');

        // Another user cannot use or read a file they did not upload.
        $elsewhere = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC9', 'name' => 'Gudang 9']);
        $elsewhere->save();
        $other = $this->createTenantUser($this->org, ['SUPERVISOR'], [['scope_type' => 'location', 'ref_id' => $elsewhere->id]]);
        $this->actingAs($other, 'web');
        $this->assertSame(404, $this->get("/api/transaction-attachments/{$file['id']}", ['Accept' => 'application/json'])->status());

        $this->actingAs($this->admin, 'web');
        $asset = $this->asset();
        $row = $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'custom', 'type_definition_id' => $type['id'],
            'field_values' => ['bast' => $file['id']]])->assertCreated()->json('data');
        $this->assertSame(['id' => $file['id'], 'name' => 'bast.pdf'], array_intersect_key($row['field_values'][0]['value'], ['id' => 1, 'name' => 1]));
        $this->assertSame('bast.pdf', $row['field_values'][0]['display']);
        $this->get("/api/transaction-attachments/{$file['id']}")->assertOk()->assertDownload('bast.pdf');

        // Claimed: it cannot be attached to a second transaction.
        $this->postJson('/api/transactions', ['asset_id' => $this->asset(), 'type' => 'custom', 'type_definition_id' => $type['id'],
            'field_values' => ['bast' => $file['id']]])->assertStatus(422);
    }

    public function test_forms_get_members_by_name_and_only_flow_managers_get_the_editor_options(): void
    {
        $supervisor = $this->createTenantUser($this->org, ['SUPERVISOR'], attributes: ['name' => 'Dewi Supervisor']);
        $viewer = $this->createTenantUser($this->org, [$this->viewerRole($this->org)], attributes: ['name' => 'Vina Viewer']);

        $this->actingAs($viewer, 'web');
        $members = $this->getJson('/api/transaction-members?search=dewi')->assertOk()->json('data');
        $this->assertSame([['id' => $supervisor->id, 'name' => 'Dewi Supervisor']], $members);
        $this->assertErrorCode($this->getJson('/api/transaction-flow-options'), 403, 'FORBIDDEN');

        $this->actingAs($this->admin, 'web');
        $options = $this->getJson('/api/transaction-flow-options')->assertOk()->json('data');
        $this->assertTrue(collect($options['members'])->firstWhere('id', $supervisor->id)['may_approve']);
        $this->assertFalse(collect($options['members'])->firstWhere('id', $viewer->id)['may_approve']);
        $this->assertSame('RUSAK_RINGAN', collect($options['conditions'])->firstWhere('label', 'Rusak Ringan')['value']);
        $this->assertTrue(collect($options['roles'])->firstWhere('name', 'Supervisor')['may_approve']);
    }

    /** Start → Persetujuan → Aksi (Dalam Perbaikan) → Selesai / Ditolak. */
    private function approvalGraph(array $approver, int $min = 1): array
    {
        return ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('setuju', 'approval', 'Persetujuan', ['approver' => $approver, 'min_approvals' => $min]),
            $this->node('aksi', 'action', 'Perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $this->edge('start', 'setuju'), $this->edge('setuju', 'aksi', 'approved'), $this->edge('setuju', 'tolak', 'rejected'), $this->edge('aksi', 'ok'),
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

    /** @return array<string, mixed> */
    private function type(): array
    {
        return $this->postJson('/api/transaction-types', [
            'code' => 'prb', 'name' => 'Perbaikan', 'status' => 'active', 'category_ids' => [$this->masters['category']->id], 'fields' => [],
        ])->assertCreated()->json('data');
    }

    private function publish(string $typeId, array $graph): void
    {
        $this->putJson("/api/transaction-types/{$typeId}/flow", ['graph' => $graph])->assertOk();
        $this->postJson("/api/transaction-types/{$typeId}/flow/publish")->assertOk();
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    private function start(string $typeId, string $assetId, array $values = [])
    {
        return $this->postJson('/api/transactions', [
            'asset_id' => $assetId, 'type' => 'custom', 'type_definition_id' => $typeId, 'field_values' => $values,
        ]);
    }

    private function complete(string $runId, string $taskId, array $body)
    {
        return $this->postJson("/api/transaction-runs/{$runId}/tasks/{$taskId}/complete", $body);
    }
}
