<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-08i (D-49): conditions on the asset's department and custodian, and the Supervisors of the
 * receiver's department (else Super Admin) as approvers. Only Super Admin and Supervisor approve
 * (D-57 withdrawn): custodians do not, nor does a named user who no longer holds such a role.
 */
class TransactionCustodianApprovalTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $recorder;

    /** @var array<string, mixed> */
    private array $masters;

    private string $deptA;

    private string $deptB;

    private ?string $memberRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $this->deptA = $this->masters['department']->id;
        $this->deptB = $this->department('DEPB', 'Keuangan');
        $this->actingAs($this->admin, 'web');
    }

    public function test_conditions_read_the_department_the_custodian_and_the_receivers_department(): void
    {
        $type = $this->type();
        $receiver = $this->member(home: $this->deptB);
        $sameDept = $this->member(home: $this->deptA);
        $noHome = $this->member();
        $mine = $this->asset(custodian: $this->admin->id, department: $this->deptA);
        $loose = $this->asset();

        $holds = fn (array $rule, string $asset, array $values = []) => $this->postJson("/api/transaction-types/{$type}/flow/simulate", [
            'graph' => $this->conditionGraph($rule), 'asset_ids' => [$asset], 'field_values' => $values,
        ])->assertOk()->json('data.path.1.outcome') === 'yes';

        $this->assertTrue($holds(['subject' => 'asset.department', 'op' => 'eq', 'value' => $this->deptA], $mine));
        $this->assertFalse($holds(['subject' => 'asset.department', 'op' => 'eq', 'value' => $this->deptA], $loose));
        $this->assertTrue($holds(['subject' => 'asset.custodian', 'op' => 'empty'], $loose));
        $this->assertTrue($holds(['subject' => 'asset.custodian', 'op' => 'not_empty'], $mine));
        $this->assertTrue($holds(['subject' => 'starter.is_custodian', 'op' => 'eq', 'value' => true], $mine));
        $this->assertFalse($holds(['subject' => 'starter.is_custodian', 'op' => 'eq', 'value' => true], $loose));

        $differs = ['subject' => 'target.department_differs', 'op' => 'eq', 'value' => 'penerima'];
        $same = [...$differs, 'op' => 'neq'];
        $this->assertTrue($holds($differs, $mine, ['penerima' => $receiver->id]));
        $this->assertFalse($holds($differs, $mine, ['penerima' => $sameDept->id]));
        $this->assertTrue($holds($same, $mine, ['penerima' => $sameDept->id]));
        // Unknown on either side: the condition does not hold, asked either way (F08-49).
        $this->assertFalse($holds($differs, $mine, ['penerima' => $noHome->id]));
        $this->assertFalse($holds($same, $mine, ['penerima' => $noHome->id]));
        $this->assertFalse($holds($differs, $loose, ['penerima' => $receiver->id]));

        // Checked like the other subjects: a user field for the receiver, the operators each subject takes.
        $issues = fn (array $rule) => array_column($this->postJson("/api/transaction-types/{$type}/flow/validate", ['graph' => $this->conditionGraph($rule)])->json('data.issues'), 'code');
        $this->assertContains('CONFIG', $issues([...$differs, 'value' => 'catatan']));
        $this->assertContains('CONFIG', $issues(['subject' => 'asset.custodian', 'op' => 'eq', 'value' => 'x']));
        $this->assertNotContains('CONFIG', $issues($differs));

        // In sentences, with names instead of ids.
        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => $this->conditionGraph($differs)])->assertOk();
        $steps = implode(' ', array_column($this->getJson("/api/transaction-types/{$type}/flow/describe")->json('data.steps'), 'sentence'));
        $this->assertStringContainsString('departemen asal Penerima berbeda dengan departemen salah satu aset', $steps);
    }

    public function test_a_custodian_never_approves_not_even_in_a_flow_published_before(): void
    {
        $type = $this->approvalType(['type' => 'location_supervisor']);
        $this->publishUnchecked($type, ['type' => 'asset_custodian']);
        $sari = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);

        // The Supervisor over the assets approves instead; being the custodian (an Admin) changes nothing.
        $run = $this->start($type, [$this->asset(custodian: $sari->id), $this->asset()])->assertCreated()->json('data');
        $this->assertSame(['location_supervisor', 1], [$run['open_task']['assignee_type'], $run['open_task']['required_approvals']]);
        $this->assertSame(403, $this->complete($run, 'approved', $sari)->status());
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');

        // A recorder who is the custodian: not approved at once, nor by themselves.
        $run = $this->start($type, [$this->asset(custodian: $this->recorder->id)])->assertCreated()->assertJsonPath('data.state', 'running')->json('data');
        $this->assertSame(403, $this->complete($run, 'approved', $this->recorder)->status());
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_open_custodian_approvals_move_to_the_supervisor_over_the_assets(): void
    {
        $type = $this->approvalType(['type' => 'location_supervisor']);
        $sari = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $run = $this->start($type, [$this->asset(custodian: $sari->id), $this->asset()])->json('data');
        $tenant = new TenantContext($this->org, $this->admin);
        $this->tenancy()->runAsTenant($tenant, fn () => DB::table('transaction_tasks')->where('id', $run['open_task']['id'])->update([
            'assignee_type' => 'asset_custodian', 'required_approvals' => 2, 'approved_by' => json_encode([$this->recorder->id]),
            'details' => json_encode(['custodians' => [$sari->id, $this->recorder->id], 'fallback' => false]),
        ]));
        $this->assertSame(403, $this->complete($run, 'approved', $sari)->status());

        $migration = require database_path('migrations/2026_11_24_000100_custodian_approvals_to_supervisors.php');
        $this->tenancy()->runAsTenant($tenant, fn () => $migration->up());

        $task = $this->getJson("/api/transaction-runs/{$run['id']}")->assertOk()->json('data.open_task');
        $this->assertSame(['location_supervisor', 1, 0], [$task['assignee_type'], $task['required_approvals'], $task['approvals']]);
        $this->assertStringContainsString('Supervisor yang membawahi aset', $task['reasons'][0]);
        $this->assertSame(403, $this->complete($run, 'approved', $sari)->status());
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_a_named_approver_who_lost_the_role_is_replaced_by_the_super_admin(): void
    {
        $budi = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR]);
        $other = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR]);
        $type = $this->approvalType(['type' => 'user', 'id' => $budi->id]);

        // While a Supervisor, only they approve.
        $run = $this->start($type, [$this->asset()])->assertCreated()->json('data');
        $this->assertSame(403, $this->complete($run, 'approved')->status());
        $this->complete($run, 'approved', $budi)->assertOk()->assertJsonPath('data.state', 'completed');

        // Made an Admin while a task is open: no longer theirs, the Super Admin approves.
        $open = $this->start($type, [$this->asset()])->json('data');
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => DB::table('user_roles')
            ->where('user_id', $budi->id)->update(['role_id' => $this->roleId($this->org, RoleTemplates::ADMIN)]));
        $this->assertSame(403, $this->complete($open, 'approved', $budi)->status());
        $this->assertSame(403, $this->complete($open, 'approved', $other)->status());
        $this->assertSame('Super Admin', $this->getJson("/api/transaction-runs/{$open['id']}")->json('data.open_task.assignee_name'));
        $this->complete($open, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');

        // A later transaction asks the Super Admin role from the start, and says why.
        $run = $this->start($type, [$this->asset()])->json('data');
        $this->assertSame(['role', $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)], [$run['open_task']['assignee_type'], $run['open_task']['assignee_id']]);
        $this->assertStringContainsString('persetujuan oleh Super Admin', $run['open_task']['reasons'][0]);
        $this->assertSame(403, $this->complete($run, 'approved', $budi)->status());
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_the_receivers_department_supervisor_approves_else_the_super_admin(): void
    {
        $type = $this->approvalType(['type' => 'target_department_supervisor', 'field' => 'penerima']);
        $spvA = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR], [['scope_type' => 'department', 'ref_id' => $this->deptA]]);
        $spvB = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR], [['scope_type' => 'department', 'ref_id' => $this->deptB]]);
        $receiver = $this->member(home: $this->deptB);

        $run = $this->start($type, [$this->asset(department: $this->deptA)], ['penerima' => $receiver->id])->assertCreated()->json('data');
        $this->assertSame(['department_supervisor', $this->deptB, 'Supervisor departemen Keuangan'],
            [$run['open_task']['assignee_type'], $run['open_task']['assignee_id'], $run['open_task']['assignee_name']]);
        $this->assertSame(403, $this->complete($run, 'approved', $spvA)->status());
        $this->complete($run, 'approved', $spvB)->assertOk()->assertJsonPath('data.state', 'completed');

        // An organization-wide Supervisor covers every department.
        $run = $this->start($type, [$this->asset()], ['penerima' => $receiver->id])->json('data');
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');

        // No home department: the Super Admin role approves, the reason kept.
        $run = $this->start($type, [$this->asset()], ['penerima' => $this->member()->id])->json('data');
        $this->assertSame(['role', $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)], [$run['open_task']['assignee_type'], $run['open_task']['assignee_id']]);
        $this->assertStringContainsString('belum punya departemen asal', $run['open_task']['reasons'][0]);
        $this->complete($run, 'approved')->assertOk()->assertJsonPath('data.state', 'completed');

        // Nobody but the recorder covers the department: the Super Admin role approves.
        $deptC = $this->department('DEPC', 'Gudang Timur');
        $this->actingAs($this->admin, 'web');
        $response = $this->postJson('/api/transactions', ['asset_ids' => [$this->asset()], 'type' => 'custom', 'type_definition_id' => $type,
            'field_values' => ['penerima' => $this->member(home: $deptC)->id]])->assertCreated();
        $this->assertSame('role', $response->json('data.open_task.assignee_type'));
        $this->assertStringContainsString('Gudang Timur', $response->json('data.open_task.reasons.0'));
    }

    public function test_approvers_are_checked_and_told(): void
    {
        $type = $this->type();
        $graph = fn (array $approver, int $min = 1) => ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('setuju', 'approval', 'Persetujuan', ['approver' => $approver, 'min_approvals' => $min]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [$this->edge('start', 'setuju'), $this->edge('setuju', 'ok', 'approved'), $this->edge('setuju', 'tolak', 'rejected')]];
        $valid = fn (array $g) => $this->postJson("/api/transaction-types/{$type}/flow/validate", ['graph' => $g])->json('data.valid');

        $this->assertTrue($valid($graph(['type' => 'location_supervisor'])));
        $this->assertFalse($valid($graph(['type' => 'asset_custodian'])));
        $this->assertTrue($valid($graph(['type' => 'target_department_supervisor', 'field' => 'penerima'])));
        $this->assertFalse($valid($graph(['type' => 'target_department_supervisor', 'field' => 'catatan'])));

        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => $graph(['type' => 'asset_custodian'])])->assertOk();
        $this->assertSame(422, $this->postJson("/api/transaction-types/{$type}/flow/publish")->status());
        // A version published before tells who approves now.
        $this->assertStringContainsString('upervisor yang membawahi aset', $this->getJson("/api/transaction-types/{$type}/flow/describe")->json('data.steps.1.sentence'));
    }

    /** An active type with a receiver (user) and a note field. */
    private function type(): string
    {
        return $this->postJson('/api/transaction-types', ['code' => 'SRT', 'name' => 'Serah terima', 'status' => 'active', 'fields' => [
            ['key' => 'penerima', 'label' => 'Penerima', 'data_type' => 'user', 'is_required' => false],
            ['key' => 'catatan', 'label' => 'Catatan', 'data_type' => 'text', 'is_required' => false],
        ]])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $approver */
    private function approvalType(array $approver): string
    {
        $type = $this->type();
        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('setuju', 'approval', 'Persetujuan', ['approver' => $approver, 'min_approvals' => 1]),
            $this->node('aksi', 'action', 'Dalam perbaikan', ['actions' => [['kind' => 'set_status', 'status' => 'under_maintenance']]]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $this->edge('start', 'setuju'), $this->edge('setuju', 'aksi', 'approved'), $this->edge('setuju', 'tolak', 'rejected'), $this->edge('aksi', 'ok'),
        ]]])->assertOk();
        $this->postJson("/api/transaction-types/{$type}/flow/publish")->assertOk();

        return $type;
    }

    /**
     * Publishes the type's flow with an approver that can no longer be chosen, as before the rule changed.
     *
     * @param  array<string, mixed>  $approver
     */
    private function publishUnchecked(string $type, array $approver): void
    {
        $graph = $this->getJson("/api/transaction-types/{$type}/flow")->assertOk()->json('data.active.graph');
        $graph['nodes'] = array_map(fn (array $n) => $n['id'] === 'setuju' ? [...$n, 'config' => [...$n['config'], 'approver' => $approver]] : $n, $graph['nodes']);
        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => $graph])->assertOk();
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () use ($type) {
            $draft = TransactionFlowVersion::query()->where('type_definition_id', $type)->where('status', TransactionFlowVersion::DRAFT)->firstOrFail();
            $draft->forceFill(['status' => TransactionFlowVersion::PUBLISHED, 'published_at' => now()])->save();
            DB::table('transaction_type_definitions')->where('id', $type)->update(['active_flow_version_id' => $draft->id]);
        });
    }

    /** @param array<string, mixed> $rule */
    private function conditionGraph(array $rule): array
    {
        return ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('cek', 'condition', 'Cek', ['match' => 'all', 'assets' => 'any', 'rules' => [$rule]]),
            $this->node('ya', 'end', 'Ya', ['result' => 'completed']),
            $this->node('tidak', 'end', 'Tidak', ['result' => 'completed']),
        ], 'edges' => [$this->edge('start', 'cek'), $this->edge('cek', 'ya', 'yes'), $this->edge('cek', 'tidak', 'no')]];
    }

    private function node(string $id, string $type, string $label, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $source, string $target, ?string $outcome = null): array
    {
        return ['id' => "{$source}-{$target}", 'source' => $source, 'target' => $target, 'outcome' => $outcome];
    }

    /**
     * A member who holds no approval role (transaction.view only).
     *
     * @param  list<array<string, mixed>>  $scopes
     */
    private function member(?string $home = null, array $scopes = [['scope_type' => 'organization']]): User
    {
        $this->memberRole ??= $this->createRole($this->org, 'KARYAWAN', ['transaction.view'])->code;
        $user = $this->createTenantUser($this->org, [$this->memberRole], $scopes);
        if ($home !== null) {
            $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
                fn () => DB::table('organization_members')->where('user_id', $user->id)->update(['home_department_id' => $home]));
        }

        return $user;
    }

    /**
     * @param  list<string>  $assetIds
     * @param  array<string, mixed>  $values
     */
    private function start(string $typeId, array $assetIds, array $values = []): TestResponse
    {
        $this->actingAs($this->recorder, 'web');
        $response = $this->postJson('/api/transactions', ['asset_ids' => $assetIds, 'type' => 'custom', 'type_definition_id' => $typeId, 'field_values' => $values]);
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

    private function asset(?string $custodian = null, ?string $department = null): string
    {
        return $this->postJson('/api/assets', array_filter([
            'name' => 'Alat '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
            'custodian_user_id' => $custodian, 'department_id' => $department,
        ]))->assertCreated()->json('data.id');
    }

    private function department(string $code, string $name): string
    {
        $department = (new Department)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $department->save();

        return $department->id;
    }

    private function location(string $code, string $name): string
    {
        $location = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $location->save();

        return $location->id;
    }
}
