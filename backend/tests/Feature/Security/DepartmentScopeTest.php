<?php

namespace Tests\Feature\Security;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-09c / D-54: department data scopes (F-09 §3.3).
 *
 * Fixture (organization ALPHA): locations Gedung A, Gedung B; departments Keuangan, IT.
 *   "Laptop Keuangan" at Gedung A (Keuangan), "Server IT" at Gedung B (IT),
 *   "Meja Gedung B" at Gedung B (no department), "Lepas" (no location, no department).
 *   "staf": department scope Keuangan, with asset and transaction management and approval.manage
 *   (changes apply directly, so the tests are about the scope rules).
 */
class DepartmentScopeTest extends TestCase
{
    private const PERMISSIONS = ['organization.view', 'master.tenant.view', 'asset.view', 'asset.create', 'asset.update',
        'asset.transfer', 'transaction.view', 'transaction.create', 'dashboard.view', 'approval.manage'];

    private Organization $org;

    /** @var array<string, mixed> */
    private array $masters;

    private Location $gedungA;

    private Location $gedungB;

    private Department $keuangan;

    private Department $it;

    /** @var array<string, Asset> */
    private array $assets = [];

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->gedungA = $this->location('GDA', 'Gedung A');
        $this->gedungB = $this->location('GDB', 'Gedung B');
        $this->keuangan = $this->department('KEU', 'Keuangan');
        $this->it = $this->department('IT', 'IT');
        $this->asset('Laptop Keuangan', $this->gedungA, $this->keuangan);
        $this->asset('Server IT', $this->gedungB, $this->it);
        $this->asset('Meja Gedung B', $this->gedungB, null);
        $this->asset('Lepas', null, null);
        $this->createRole($this->org, 'STAF', self::PERMISSIONS);
    }

    public function test_department_scope_sees_its_department_assets_wherever_they_are(): void
    {
        $user = $this->staff([$this->deptScope($this->keuangan)]);
        $this->loginAs($user);

        $this->assertSame(['Laptop Keuangan'], $this->listedNames());
        $this->getJson("/api/assets/{$this->assets['Laptop Keuangan']->id}")->assertOk();
        $this->getJson("/api/assets/{$this->assets['Server IT']->id}")->assertNotFound();
        $this->getJson("/api/assets/{$this->assets['Lepas']->id}")->assertNotFound();
        $this->assertSame(1, $this->widgetData($this->org, $user, 'total_assets')['value']);
    }

    public function test_several_entries_are_a_union_and_unassigned_assets_stay_organization_only(): void
    {
        $this->loginAs($this->staff([$this->deptScope($this->it), ['scope_type' => 'location', 'ref_id' => $this->gedungA->id]]));
        $this->assertSame(['Laptop Keuangan', 'Server IT'], $this->listedNames());

        $this->loginAs($this->staff([['scope_type' => 'organization']]));
        $this->assertSame(['Laptop Keuangan', 'Lepas', 'Meja Gedung B', 'Server IT'], $this->listedNames());
    }

    public function test_department_user_registers_only_into_their_department_at_any_location(): void
    {
        $this->loginAs($this->staff([$this->deptScope($this->keuangan)]));

        $this->register(['location_id' => $this->gedungB->id, 'department_id' => $this->keuangan->id])->assertCreated();
        $this->assertErrorCode($this->register(['location_id' => $this->gedungB->id, 'department_id' => $this->it->id]), 422, 'ASSET_OUT_OF_SCOPE');
        $this->assertErrorCode($this->register(['location_id' => $this->gedungB->id]), 422, 'ASSET_OUT_OF_SCOPE');
    }

    public function test_location_user_cannot_use_a_department_to_reach_another_location(): void
    {
        $this->loginAs($this->staff([['scope_type' => 'location', 'ref_id' => $this->gedungA->id]]));

        $this->assertErrorCode($this->register(['location_id' => $this->gedungB->id, 'department_id' => $this->keuangan->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->register(['location_id' => $this->gedungA->id, 'department_id' => $this->it->id])->assertCreated();
    }

    public function test_an_asset_cannot_be_pushed_out_of_or_pulled_into_the_users_reach(): void
    {
        $user = $this->staff([$this->deptScope($this->keuangan)]);
        $this->loginAs($user);
        $laptop = $this->assets['Laptop Keuangan'];

        // Push out: moving their own asset to another department would hide it from them.
        $this->assertErrorCode($this->patchJson("/api/assets/{$laptop->id}", ['department_id' => $this->it->id]), 422, 'ASSET_OUT_OF_SCOPE');
        $this->assertErrorCode($this->patchJson("/api/assets/{$laptop->id}", ['department_id' => null]), 422, 'ASSET_OUT_OF_SCOPE');
        // Pull in: another department's asset is not reachable at all.
        $this->patchJson("/api/assets/{$this->assets['Server IT']->id}", ['department_id' => $this->keuangan->id])->assertNotFound();
        $this->assertSame($this->it->id, Asset::query()->withoutGlobalScopes()->whereKey($this->assets['Server IT']->id)->value('department_id'));

        // Other edits of their own asset still work.
        $this->patchJson("/api/assets/{$laptop->id}", ['name' => 'Laptop Keuangan 2'])->assertOk();

        // A user with both departments may move the asset between them.
        $this->loginAs($this->staff([$this->deptScope($this->keuangan), $this->deptScope($this->it)]));
        $this->patchJson("/api/assets/{$laptop->id}", ['department_id' => $this->it->id])->assertOk();
    }

    public function test_department_user_may_transfer_their_department_asset_to_any_location(): void
    {
        $this->loginAs($this->staff([$this->deptScope($this->keuangan)]));
        $laptop = $this->assets['Laptop Keuangan'];

        $this->postJson('/api/transactions', ['asset_id' => $laptop->id, 'type' => 'transfer', 'to_location_id' => $this->gedungB->id])->assertCreated();
        $this->getJson("/api/assets/{$laptop->id}")->assertOk()->assertJsonPath('data.location_id', $this->gedungB->id);
        $this->postJson('/api/transactions', ['asset_id' => $this->assets['Server IT']->id, 'type' => 'transfer', 'to_location_id' => $this->gedungA->id])->assertNotFound();

        // Their transactions list shows their department's assets only.
        $assetIds = array_unique(array_column($this->getJson('/api/transactions?per_page=100')->assertOk()->json('data'), 'asset_id'));
        $this->assertSame([$laptop->id], array_values($assetIds));
    }

    public function test_location_user_still_cannot_transfer_outside_their_locations(): void
    {
        $this->loginAs($this->staff([['scope_type' => 'location', 'ref_id' => $this->gedungA->id]]));
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $this->assets['Laptop Keuangan']->id, 'type' => 'transfer', 'to_location_id' => $this->gedungB->id]), 422, 'VALIDATION_FAILED');
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<string> */
    private function listedNames(): array
    {
        $names = array_column($this->getJson('/api/assets?per_page=100')->assertOk()->json('data'), 'name');
        sort($names);

        return $names;
    }

    /** @param list<array{scope_type: string, ref_id?: string}> $scopes */
    private function staff(array $scopes): User
    {
        return $this->createTenantUser($this->org, ['STAF'], $scopes);
    }

    /** @return array{scope_type: string, ref_id: string} */
    private function deptScope(Department $department): array
    {
        return ['scope_type' => 'department', 'ref_id' => $department->id];
    }

    private function loginAs(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'web');
    }

    /** @param array<string, mixed> $data */
    private function register(array $data)
    {
        $this->relaxDefaultCondition();

        return $this->postJson('/api/assets', [
            'name' => 'Baru', 'status' => 'available', 'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id, ...$data,
        ]);
    }

    private function location(string $code, string $name): Location
    {
        $location = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $location->save();

        return $location->refresh();
    }

    private function department(string $code, string $name): Department
    {
        $department = (new Department)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $department->save();

        return $department;
    }

    private function asset(string $name, ?Location $location, ?Department $department): void
    {
        $fixture = $this->createAssetFixture($this->org, ['location' => $location ?? $this->gedungA, 'category' => $this->masters['category']], Str::random(6));
        $asset = $fixture['asset'];
        Asset::query()->withoutGlobalScopes()->whereKey($asset->id)
            ->update(['name' => $name, 'location_id' => $location?->id, 'department_id' => $department?->id]);
        $this->assets[$name] = $asset;
    }
}
