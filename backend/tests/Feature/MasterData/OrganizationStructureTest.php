<?php

namespace Tests\Feature\MasterData;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\AssetCondition;
use Tests\TestCase;

/** Departments, locations (hierarchy) and asset conditions of one organization. */
class OrganizationStructureTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private string $warehouseType;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->warehouseType = LocationType::query()->where('code', 'GUDANG')->value('id');
        $this->actingAs($this->admin, 'web');
    }

    public function test_department_location_crud_with_audit(): void
    {
        $department = $this->postJson('/api/departments', ['code' => 'ops', 'name' => 'Operasional'])
            ->assertCreated()->assertJsonPath('data.code', 'OPS')->assertJsonPath('data.status', 'active')->json('data');
        $this->assertErrorCode($this->postJson('/api/departments', ['code' => 'OPS', 'name' => 'Duplikat']), 422, 'VALIDATION_FAILED');
        $this->patchJson("/api/departments/{$department['id']}", ['name' => 'Operasi Lapangan'])->assertOk()->assertJsonPath('data.name', 'Operasi Lapangan');

        $location = $this->createLocation('GDG');
        $this->getJson('/api/locations')->assertOk()->assertJsonPath('data.0.location_type.code', 'GUDANG');
        $this->getJson("/api/locations/{$location['id']}")->assertOk()->assertJsonPath('data.depth', 0);

        foreach (['department.created', 'department.updated', 'location.created'] as $action) {
            $this->assertTrue(AuditLog::query()->where('organization_id', $this->org->id)->where('action', $action)->exists(), $action);
        }
        $updated = AuditLog::query()->where('action', 'department.updated')->firstOrFail();
        $this->assertSame(['name' => 'Operasional'], $updated->before);
        $this->assertSame(['name' => 'Operasi Lapangan'], $updated->after);
    }

    public function test_same_code_is_allowed_in_another_organization(): void
    {
        $this->postJson('/api/departments', ['code' => 'PUSAT', 'name' => 'Kantor Pusat'])->assertCreated();

        ['admin' => $adminB] = $this->createOrganization('BETA');
        $this->actingAs($adminB, 'web');

        $this->postJson('/api/departments', ['code' => 'PUSAT', 'name' => 'Kantor Pusat B'])->assertCreated();
    }

    public function test_location_hierarchy_paths_moves_and_cycles(): void
    {
        $building = $this->createLocation('GEDUNG-A');
        $floor = $this->createLocation('LT-1', $building['id']);
        $room = $this->createLocation('R-101', $floor['id']);
        $other = $this->createLocation('GEDUNG-B');

        $this->assertSame(2, $room['depth']);
        $paths = Location::query()->pluck('path', 'code');
        $this->assertSame("/{$building['id']}/{$floor['id']}/{$room['id']}/", $paths['R-101']);

        // Moving a subtree rewrites the paths of all descendants.
        $this->patchJson("/api/locations/{$floor['id']}", ['parent_id' => $other['id']])->assertOk()->assertJsonPath('data.parent.code', 'GEDUNG-B');
        $this->assertSame("/{$other['id']}/{$floor['id']}/{$room['id']}/", Location::query()->whereKey($room['id'])->value('path'));

        // A node cannot be moved under itself or its descendants.
        $this->assertErrorCode($this->patchJson("/api/locations/{$floor['id']}", ['parent_id' => $room['id']]), 422, 'INVALID_PARENT');
        $this->assertErrorCode($this->patchJson("/api/locations/{$floor['id']}", ['parent_id' => $floor['id']]), 422, 'INVALID_PARENT');
    }

    public function test_records_in_use_cannot_be_archived_and_archived_ones_cannot_be_referenced(): void
    {
        $parent = $this->createLocation('GDG');
        $child = $this->createLocation('RAK-1', $parent['id']);

        $this->assertErrorCode($this->postJson("/api/locations/{$parent['id']}/archive"), 409, 'LOCATION_HAS_CHILDREN');
        $this->assertErrorCode($this->patchJson("/api/locations/{$parent['id']}", ['status' => 'inactive']), 409, 'LOCATION_HAS_CHILDREN');

        $this->postJson("/api/locations/{$child['id']}/archive", ['reason' => 'Rak dibongkar'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/locations/{$parent['id']}/archive")->assertOk();

        // Archived records are hidden by default but kept; they cannot be used as new references.
        $this->getJson('/api/locations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/locations?status=archived')->assertOk()->assertJsonCount(2, 'data');
        $this->assertErrorCode($this->postJson('/api/locations', ['code' => 'X', 'name' => 'X', 'parent_id' => $parent['id'], 'location_type_id' => $this->warehouseType]), 422, 'INVALID_REFERENCE');

        // Restore requires an active parent.
        $this->assertErrorCode($this->postJson("/api/locations/{$child['id']}/restore"), 422, 'INVALID_REFERENCE');
        $this->postJson("/api/locations/{$parent['id']}/restore")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/locations/{$child['id']}/restore")->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'location.archived')->where('metadata->reason', 'Rak dibongkar')->exists());
    }

    public function test_code_cannot_be_changed_after_creation(): void
    {
        $id = $this->postJson('/api/departments', ['code' => 'OPS', 'name' => 'Operasional'])->json('data.id');

        $this->patchJson("/api/departments/{$id}", ['code' => 'XXX', 'name' => 'Operasi Raya'])->assertOk()->assertJsonPath('data.code', 'OPS');
    }

    public function test_standard_conditions_are_copied_at_provisioning_and_import_is_idempotent(): void
    {
        $conditions = $this->getJson('/api/tenant-masters/conditions')->assertOk()->json('data');
        $this->assertSame(['BAIK', 'RUSAK_RINGAN', 'RUSAK_BERAT', 'HILANG'], array_column($conditions, 'code'));
        $this->assertNotNull($conditions[0]['generic_condition_id']);

        $this->postJson('/api/tenant-masters/conditions/import-generic')->assertOk()->assertJsonPath('meta.created', 0);

        // Renaming the tenant copy never changes the generic condition.
        $this->patchJson("/api/tenant-masters/conditions/{$conditions[0]['id']}", ['name' => 'Layak Pakai'])->assertOk();
        $this->assertSame('Baik', GenericCondition::query()->where('code', 'BAIK')->value('name'));

        // A new generic condition can be pulled in later.
        $this->tenancy()->runAsSystem(fn () => (new GenericCondition)->forceFill(['code' => 'DALAM_PERBAIKAN', 'name' => 'Dalam Perbaikan', 'severity' => 3, 'sort_order' => 5])->save());
        $this->postJson('/api/tenant-masters/conditions/import-generic')->assertOk()->assertJsonPath('meta.created', 1);
        $this->assertSame(5, AssetCondition::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_operator_can_read_but_not_manage_structure(): void
    {
        $this->actingAs($this->createTenantUser($this->org, ['OPERATOR']), 'web');

        $this->getJson('/api/departments')->assertOk();
        $this->getJson('/api/locations?all=1')->assertOk();
        $this->assertErrorCode($this->postJson('/api/departments', ['code' => 'X', 'name' => 'X']), 403, 'FORBIDDEN');
    }

    /** @return array<string, mixed> */
    private function createLocation(string $code, ?string $parentId = null): array
    {
        return $this->postJson('/api/locations', [
            'code' => $code, 'name' => "Lokasi {$code}", 'parent_id' => $parentId, 'location_type_id' => $this->warehouseType,
        ])->assertCreated()->json('data');
    }
}
