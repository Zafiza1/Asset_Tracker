<?php

namespace Tests\Feature\Security;

use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-08k (F-08 §6): the flow action "Ganti penanggung jawab" (set_custodian). The new custodian
 * is an active member of the same organization, and the department it brings may not push the
 * asset out of the recorder's data scope (F-09 §3.3), whoever completes the last step.
 */
class TransactionCustodianActionTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    private string $finance;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $finance = (new Department)->forceFill(['organization_id' => $this->org->id, 'code' => 'DEPB', 'name' => 'Keuangan']);
        $finance->save();
        $this->finance = $finance->id;
        $this->actingAs($this->admin, 'web');
    }

    public function test_the_new_department_may_not_push_the_asset_out_of_the_recorders_scope(): void
    {
        $type = $this->type(setDepartment: true);
        // A recorder whose scope is the asset's department only.
        $recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN], [['scope_type' => 'department', 'ref_id' => $this->masters['department']->id]]);
        $asset = $this->asset();
        $outside = $this->member($this->finance);

        $this->actingAs($recorder, 'web');
        $this->assertErrorCode($this->start($type, $asset, $outside), 422, 'ASSET_OUT_OF_SCOPE');
        $this->actingAs($this->admin, 'web');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.custodian_user_id', null)
            ->assertJsonPath('data.department_id', $this->masters['department']->id);

        // Within the recorder's department the handover is fine; an organization-wide recorder may move it anywhere.
        $this->actingAs($recorder, 'web');
        $this->start($type, $asset, $this->member($this->masters['department']->id))->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->actingAs($this->admin, 'web');
        $this->start($type, $asset, $outside)->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.department_id', $this->finance);
    }

    public function test_the_recorders_scope_holds_when_someone_else_completes_the_last_step(): void
    {
        $type = $this->type(setDepartment: true, approval: true);
        $recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN], [['scope_type' => 'department', 'ref_id' => $this->masters['department']->id]]);
        $asset = $this->asset();

        $this->actingAs($recorder, 'web');
        $run = $this->start($type, $asset, $this->member($this->finance))->assertCreated()->json('data');
        // The organization-wide Super Admin approves; the department would still leave the recorder's reach.
        $this->actingAs($this->admin, 'web');
        $this->assertErrorCode($this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'approved']), 422, 'ASSET_OUT_OF_SCOPE');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.custodian_user_id', null);
        $this->assertSame('running', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
    }

    public function test_the_new_custodian_is_an_active_member_of_the_organization(): void
    {
        $type = $this->type(setDepartment: false, approval: true);
        $asset = $this->asset();
        $recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);

        // A member of another organization is no value for the user field.
        ['organization' => $other] = $this->createOrganization('BETA');
        $stranger = $this->createTenantUser($other);
        $this->actingAs($recorder, 'web');
        $this->start($type, $asset, $stranger)->assertStatus(422);

        // Deactivated before the action runs: the step is refused and the asset keeps its custodian.
        $leaving = $this->member(null);
        $run = $this->start($type, $asset, $leaving)->assertCreated()->json('data');
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('organization_members')->where('user_id', $leaving->id)->update(['status' => User::STATUS_DEACTIVATED]));
        $this->actingAs($this->admin, 'web');
        $this->assertErrorCode($this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'approved']), 422, 'INVALID_REFERENCE');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.custodian_user_id', null);

        // Without set_department the asset keeps its department.
        $active = $this->member($this->finance);
        $this->actingAs($recorder, 'web');
        $this->actingAs($this->admin, 'web');
        $second = $this->asset();
        $this->actingAs($recorder, 'web');
        $run = $this->start($type, $second, $active)->json('data');
        $this->actingAs($this->admin, 'web');
        $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$second}")->assertJsonPath('data.custodian_user_id', $active->id)
            ->assertJsonPath('data.department_id', $this->masters['department']->id);
    }

    /** Mulai → [Persetujuan Super Admin →] Ganti penanggung jawab → Selesai. */
    private function type(bool $setDepartment, bool $approval = false): string
    {
        $type = $this->postJson('/api/transaction-types', ['code' => $setDepartment ? 'GPJD' : 'GPJ', 'name' => 'Ganti PJ', 'status' => 'active', 'fields' => [
            ['key' => 'baru', 'label' => 'Penanggung jawab baru', 'data_type' => 'user', 'is_required' => true],
        ]])->assertCreated()->json('data.id');
        $node = fn (string $id, string $t, array $config = []) => ['id' => $id, 'type' => $t, 'label' => $id, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
        $edge = fn (string $s, string $t, ?string $o = null) => ['id' => "{$s}-{$t}", 'source' => $s, 'target' => $t, 'outcome' => $o];
        $nodes = [$node('start', 'start'), $node('ganti', 'action', ['actions' => [['kind' => 'set_custodian', 'user_field' => 'baru', 'set_department' => $setDepartment]]]),
            $node('ok', 'end', ['result' => 'completed'])];
        $edges = [$edge('ganti', 'ok')];
        if ($approval) {
            $nodes[] = $node('setuju', 'approval', ['approver' => ['type' => 'role', 'id' => $this->roleId($this->org, RoleTemplates::SUPER_ADMIN)], 'min_approvals' => 1]);
            $nodes[] = $node('tolak', 'end', ['result' => 'rejected']);
            array_push($edges, $edge('start', 'setuju'), $edge('setuju', 'ganti', 'approved'), $edge('setuju', 'tolak', 'rejected'));
        } else {
            $edges[] = $edge('start', 'ganti');
        }
        $this->putJson("/api/transaction-types/{$type}/flow", ['graph' => ['nodes' => $nodes, 'edges' => $edges]])->assertOk();
        $this->postJson("/api/transaction-types/{$type}/flow/publish")->assertOk();

        return $type;
    }

    private function member(?string $home): User
    {
        $user = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('organization_members')->where('user_id', $user->id)->update(['home_department_id' => $home]));

        return $user;
    }

    private function start(string $type, string $asset, User $to): TestResponse
    {
        return $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'custom', 'type_definition_id' => $type, 'field_values' => ['baru' => $to->id]]);
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
            'department_id' => $this->masters['department']->id,
        ])->assertCreated()->json('data.id');
    }
}
