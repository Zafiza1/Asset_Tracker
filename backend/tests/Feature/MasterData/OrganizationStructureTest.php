<?php

namespace Tests\Feature\MasterData;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Departments and locations (hierarchy) of one organization. */
class OrganizationStructureTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->actingAs($this->admin, 'web');
    }

    public function test_department_location_crud_with_audit(): void
    {
        $department = $this->postJson('/api/departments', ['code' => 'ops', 'name' => 'Operasional'])
            ->assertCreated()->assertJsonPath('data.code', 'OPS')->assertJsonPath('data.status', 'active')->json('data');
        $this->assertErrorCode($this->postJson('/api/departments', ['code' => 'OPS', 'name' => 'Duplikat']), 422, 'VALIDATION_FAILED');
        $this->patchJson("/api/departments/{$department['id']}", ['name' => 'Operasi Lapangan'])->assertOk()->assertJsonPath('data.name', 'Operasi Lapangan');

        $location = $this->createLocation('GDG');
        $this->getJson('/api/locations')->assertOk()->assertJsonPath('data.0.code', 'GDG')->assertJsonMissingPath('data.0.location_type_id');
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

    public function test_location_list_filters_by_subtree_and_level_and_counts_dependents(): void
    {
        $building = $this->createLocation('GEDUNG-A');
        $floor = $this->createLocation('LT-1', $building['id']);
        $this->createLocation('R-101', $floor['id']);
        $this->createLocation('GEDUNG-B');
        $this->patchJson("/api/locations/{$floor['id']}", ['address' => 'Jl. Merdeka 10'])->assertOk();

        $codes = fn (string $query) => array_column($this->getJson("/api/locations{$query}")->assertOk()->json('data'), 'code');
        $this->assertSame(['GEDUNG-A', 'LT-1', 'R-101'], $codes("?parent_id={$building['id']}"));
        $this->assertEqualsCanonicalizing(['GEDUNG-A', 'GEDUNG-B'], $codes('?level=root'));
        $this->assertEqualsCanonicalizing(['LT-1', 'R-101'], $codes('?level=sub'));
        $this->assertSame([], $codes('?parent_id='.Str::uuid()));
        // The address is searched too.
        $this->assertSame(['LT-1'], $codes('?search=merdeka'));

        $rows = collect($this->getJson('/api/locations')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(1, $rows['GEDUNG-A']['children_count']);
        $this->assertSame(0, $rows['R-101']['children_count']);
        $this->assertSame(0, $rows['GEDUNG-A']['assets_count']);
        $this->getJson('/api/locations?level=x')->assertUnprocessable();

        // Active assets placed in a location are counted (they block archiving it).
        $masters = $this->createMasterFixtures($this->org, '9');
        $this->createAssetFixture($this->org, $masters, '9');
        $this->assertSame(1, collect($this->getJson('/api/locations?search=LOC9')->json('data'))->first()['assets_count']);
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
        $this->assertErrorCode($this->postJson('/api/locations', ['code' => 'X', 'name' => 'X', 'parent_id' => $parent['id']]), 422, 'INVALID_REFERENCE');

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

    public function test_empty_code_is_generated_from_name(): void
    {
        $this->postJson('/api/departments', ['name' => 'Keuangan & Pajak'])->assertCreated()->assertJsonPath('data.code', 'KEUANGAN-PAJAK');
        $this->postJson('/api/departments', ['code' => '', 'name' => 'Keuangan Pajak'])->assertCreated()->assertJsonPath('data.code', 'KEUANGAN-PAJAK-2');
        $this->postJson('/api/departments', ['code' => null, 'name' => '!!!'])->assertCreated()->assertJsonPath('data.code', 'KODE');

        $long = str_repeat('Gudang ', 10);
        $first = $this->postJson('/api/locations', ['name' => $long])->assertCreated()->json('data.code');
        $second = $this->postJson('/api/locations', ['name' => $long])->assertCreated()->json('data.code');
        $this->assertSame('GUDANG-GUDANG-GUDANG-GUDANG-GU', $first);
        $this->assertSame('GUDANG-GUDANG-GUDANG-GUDANG-2', $second);

        $this->postJson('/api/tenant-masters/categories', ['name' => 'Perangkat IT'])->assertCreated()->assertJsonPath('data.code', 'PERANGKAT-IT');
    }

    public function test_viewer_can_read_but_not_manage_structure(): void
    {
        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');

        $this->getJson('/api/departments')->assertOk();
        $this->getJson('/api/locations?all=1')->assertOk();
        $this->assertErrorCode($this->postJson('/api/departments', ['code' => 'X', 'name' => 'X']), 403, 'FORBIDDEN');
    }

    /** @return array<string, mixed> */
    private function createLocation(string $code, ?string $parentId = null): array
    {
        return $this->postJson('/api/locations', [
            'code' => $code, 'name' => "Lokasi {$code}", 'parent_id' => $parentId,
        ])->assertCreated()->json('data');
    }
}
