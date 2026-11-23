<?php

namespace Tests\Feature\Assets;

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
 * F-08i (D-49, D-57): conditions on the asset's department and custodian, and two approvers:
 * the custodians of the assets (members without an approval role, with a Supervisor for assets
 * without one and the recorder's own assets approved at once), and the Supervisors of the
 * receiver's department (else Super Admin).
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

    public function test_each_custodian_approves_once_and_only_while_the_task_is_theirs(): void
    {
        $type = $this->approvalType(['type' => 'asset_custodian']);
        $elsewhere = $this->location('LOC9', 'Gudang 9');
        $sari = $this->member(scopes: [['scope_type' => 'location', 'ref_id' => $elsewhere]]);
        $budi = $this->member(scopes: [['scope_type' => 'location', 'ref_id' => $elsewhere]]);
        $other = $this->member();
        $assets = [$this->asset(custodian: $sari->id), $this->asset(custodian: $sari->id), $this->asset(custodian: $budi->id)];

        $run = $this->start($type, $assets)->assertCreated()->json('data');
        $this->assertSame(['asset_custodian', 2, 'Penanggung jawab aset'], [$run['open_task']['assignee_type'], $run['open_task']['required_approvals'], $run['open_task']['assignee_name']]);

        // A custodian sees the run only through the task, though the assets are outside their scope.
        $this->actingAs($sari, 'web');
        $this->getJson("/api/transaction-runs/{$run['id']}")->assertOk()->assertJsonPath('data.open_task.can_act', true);
        $this->actingAs($this->admin, 'web');
        $this->assertSame(403, $this->complete($run, 'approved', $other)->status(), 'not a custodian of these assets');
        $this->assertSame(403, $this->complete($run, 'approved')->status(), 'no Supervisor part: every asset has a custodian');

        $run = $this->complete($run, 'approved', $sari)->assertOk()->json('data');
        $this->assertSame(['running', 'setuju'], [$run['state'], $run['current_step']['id']]);
        $this->assertSame(404, $this->complete($run, 'approved', $sari)->status(), 'one approval per custodian: the task is no longer theirs');
        $this->complete($run, 'approved', $budi)->assertOk()->assertJsonPath('data.state', 'completed');

        // The task done, a custodian without the assets in scope no longer sees the run.
        $this->actingAs($sari, 'web');
        $this->assertSame(404, $this->getJson("/api/transaction-runs/{$run['id']}")->status());
        $this->actingAs($this->admin, 'web');

        // One custodian refusing refuses the transaction.
        $run = $this->start($type, [$this->asset(custodian: $sari->id), $this->asset(custodian: $budi->id)])->json('data');
        $this->complete($run, 'rejected', $budi)->assertOk()->assertJsonPath('data.state', 'rejected');
    }

    public function test_assets_without_an_active_custodian_go_to_the_supervisor_and_the_recorders_own_are_approved(): void
    {
        $type = $this->approvalType(['type' => 'asset_custodian']);
        $sari = $this->member();
        $gone = $this->member();
        $absent = $this->asset(custodian: $gone->id);
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('organization_members')->where('user_id', $gone->id)->update(['status' => User::STATUS_DEACTIVATED]));

        // No custodian, and an inactive one: the Supervisor over the assets approves once, the reasons kept.
        $run = $this->start($type, [$this->asset(), $absent, $this->asset(custodian: $sari->id)])->json('data');
        $reasons = implode(' ', $run['open_task']['reasons']);
        $this->assertStringContainsString('belum punya penanggung jawab', $reasons);
        $this->assertStringContainsString('tidak aktif', $reasons);
        $run = $this->complete($run, 'approved')->assertOk()->json('data');
        $this->assertSame('running', $run['state'], 'the custodian still approves for their asset');
        $this->assertSame(409, $this->complete($run, 'approved')->status(), 'the Supervisor part is given');
        $this->complete($run, 'approved', $sari)->assertOk()->assertJsonPath('data.state', 'completed');

        // The recorder is the only custodian: approved at once, and the history says so.
        $run = $this->start($type, [$this->asset(custodian: $this->recorder->id)])->assertCreated()->assertJsonPath('data.state', 'completed')->json('data');
        $this->actingAs($this->recorder, 'web');
        $notes = array_column($this->getJson("/api/transaction-runs/{$run['id']}")->assertOk()->json('data.steps'), 'note');
        $this->assertContains('Disetujui otomatis: pencatat adalah penanggung jawab.', $notes);
        $this->actingAs($this->admin, 'web');
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

    public function test_custodian_approvers_are_checked_and_told(): void
    {
        $type = $this->type();
        $graph = fn (array $approver, int $min = 1) => ['nodes' => [
            $this->node('start', 'start', 'Mulai'),
            $this->node('setuju', 'approval', 'Persetujuan', ['approver' => $approver, 'min_approvals' => $min]),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [$this->edge('start', 'setuju'), $this->edge('setuju', 'ok', 'approved'), $this->edge('setuju', 'tolak', 'rejected')]];
        $valid = fn (array $g) => $this->postJson("/api/transaction-types/{$type}/flow/validate", ['graph' => $g])->json('data.valid');

        $this->assertTrue($valid($graph(['type' => 'asset_custodian'])));
        $this->assertFalse($valid($graph(['type' => 'asset_custodian'], 2)));
        $this->assertTrue($valid($graph(['type' => 'target_department_supervisor', 'field' => 'penerima'])));
        $this->assertFalse($valid($graph(['type' => 'target_department_supervisor', 'field' => 'catatan'])));

        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => $graph(['type' => 'asset_custodian'])])->assertOk();
        $this->assertStringContainsString('Penanggung jawab setiap aset', $this->getJson("/api/transaction-types/{$type}/flow/describe")->json('data.steps.1.sentence'));
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
