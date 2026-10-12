<?php

namespace Tests\Feature\Security;

use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec §16 scenarios for master data: tenants cannot change the Generic Master, cannot touch
 * another tenant's custom master, and cross-tenant references are rejected by the database.
 */
class MasterDataSecurityTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    /** @var array<string, Model> */
    private array $mastersB;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB] = $this->createOrganization('BETA');
        $this->mastersB = $this->createMasterFixtures($this->orgB);
    }

    public function test_tenant_cannot_use_generic_master_management_endpoints(): void
    {
        $this->actingAs($this->adminA, 'web');
        $definition = GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->firstOrFail();
        $version = $definition->versions()->firstOrFail();

        $this->assertErrorCode($this->postJson('/api/platform/generic-masters/categories', ['code' => 'X', 'name' => 'X']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->patchJson("/api/platform/generic-masters/definitions/{$definition->id}", ['name' => 'Dibajak']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/definitions/{$definition->id}/versions"), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/versions/{$version->id}/deprecate"), 403, 'FORBIDDEN');

        $this->assertSame('Aset Umum', $definition->fresh()->name);
        $this->assertSame(1, $definition->versions()->count());
    }

    public function test_generic_tables_reject_writes_from_tenant_context_at_database_level(): void
    {
        $attributeId = GenericAttributeDefinition::query()->value('id');

        $this->asTenantA(function () use ($attributeId) {
            $this->expectQueryError('42501', fn () => DB::table('generic_asset_definitions')->update(['name' => 'Dibajak']));
            $this->expectQueryError('42501', fn () => DB::table('generic_asset_categories')->insert([
                'id' => (string) Str::uuid7(), 'code' => 'HACK', 'name' => 'Hack', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->expectQueryError('42501', fn () => DB::table('generic_attribute_definitions')->where('id', $attributeId)->delete());
            $this->expectQueryError('42501', fn () => DB::table('location_types')->update(['name' => 'X']));
            $this->expectQueryError('42501', fn () => DB::table('units')->delete());
        });

        $this->assertNotSame('Dibajak', GenericAssetDefinition::query()->value('name'));
    }

    public function test_tenant_catalog_never_exposes_generic_drafts(): void
    {
        $definition = GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->firstOrFail();
        $this->actingAs($this->createPlatformAdmin(), 'web');
        $draftId = $this->postJson("/api/platform/generic-masters/definitions/{$definition->id}/versions")->json('data.id');

        $this->actingAs($this->adminA, 'web');
        $this->assertErrorCode($this->getJson("/api/generic-masters/versions/{$draftId}"), 404, 'NOT_FOUND');
        $versions = $this->getJson("/api/generic-masters/definitions/{$definition->id}")->assertOk()->json('data.versions');
        $this->assertSame(['released'], array_values(array_unique(array_column($versions, 'status'))));
        $released = $definition->versions()->where('status', 'released')->value('id');
        $this->getJson("/api/generic-masters/versions/{$released}")->assertOk()->assertJsonCount(2, 'data.attributes');
    }

    public function test_tenant_cannot_read_or_modify_another_tenants_custom_master(): void
    {
        $this->actingAs($this->adminA, 'web');
        $definition = $this->mastersB['definition'];
        $version = $this->mastersB['version'];

        $this->assertErrorCode($this->getJson("/api/asset-definitions/{$definition->id}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/asset-definitions/{$definition->id}", ['name' => 'Dibajak']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/asset-definition-versions/{$version->id}/release"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->putJson("/api/asset-definition-versions/{$version->id}/overrides", ['overrides' => []]), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/locations/{$this->mastersB['location']->id}", ['name' => 'X']), 404, 'NOT_FOUND');

        $this->assertSame('Definisi 1', $definition->fresh()->name);
        $this->assertSame('draft', $version->fresh()->status);
        $this->assertCount(0, $this->getJson('/api/asset-definitions')->json('data'));
        $this->assertCount(0, $this->getJson('/api/locations?all=1')->json('data'));
    }

    public function test_references_to_another_tenants_masters_are_rejected_by_the_service(): void
    {
        $this->actingAs($this->adminA, 'web');
        $typeId = $this->mastersB['location']->location_type_id;

        $this->assertErrorCode($this->postJson('/api/locations', ['code' => 'L', 'name' => 'L', 'parent_id' => $this->mastersB['location']->id, 'location_type_id' => $typeId]), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->postJson('/api/tenant-masters/categories', ['code' => 'C', 'name' => 'C', 'parent_id' => $this->mastersB['category']->id]), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->postJson('/api/asset-definitions', [
            'generic_definition_id' => GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->value('id'),
            'code' => 'X', 'name' => 'X', 'tenant_category_id' => $this->mastersB['category']->id,
        ]), 422, 'INVALID_REFERENCE');
    }

    public function test_cross_tenant_foreign_keys_are_rejected_by_the_database(): void
    {
        $generic = GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->firstOrFail();

        $this->expectQueryError('23503', fn () => DB::table('tenant_asset_definitions')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id, 'generic_definition_id' => $generic->id,
            'tenant_category_id' => $this->mastersB['category']->id, 'code' => 'X', 'name' => 'X',
        ]));
        $this->expectQueryError('23503', fn () => DB::table('tenant_attribute_definitions')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id,
            'tenant_definition_version_id' => $this->mastersB['version']->id, 'key' => 'x', 'label' => 'X', 'data_type' => 'string',
        ]));
        $this->expectQueryError('23503', fn () => DB::table('tenant_asset_categories')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id, 'parent_id' => $this->mastersB['category']->id,
            'generic_category_id' => $this->mastersB['category']->generic_category_id, 'code' => 'X', 'name' => 'X',
        ]));
    }

    public function test_tenant_version_must_pin_a_released_version_of_its_own_generic_definition(): void
    {
        $other = GenericAssetDefinition::query()->where('code', 'GEN-LAPTOP')->firstOrFail()->versions()->value('id');

        // Pinned to a version of a different generic definition than the one adopted.
        $this->expectQueryError('P0001', fn () => DB::table('tenant_asset_definition_versions')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->orgB->id, 'tenant_definition_id' => $this->mastersB['definition']->id,
            'generic_definition_version_id' => $other, 'version_number' => 2, 'status' => 'draft',
        ]));
        $this->expectQueryError('P0001', fn () => DB::table('tenant_asset_definitions')->where('id', $this->mastersB['definition']->id)
            ->update(['generic_definition_id' => GenericAssetDefinition::query()->where('code', 'GEN-LAPTOP')->value('id')]));
    }

    public function test_row_level_security_hides_tenant_masters_of_other_organizations(): void
    {
        $this->asTenantA(function () {
            foreach (['tenant_asset_categories', 'tenant_asset_definitions', 'tenant_asset_definition_versions',
                'tenant_attribute_definitions', 'departments', 'locations'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertSame(4, DB::table('asset_conditions')->count());
            $this->assertSame(0, TenantAssetDefinition::query()->withoutGlobalScopes()->count());
            $this->assertSame(0, TenantDefinitionVersion::query()->withoutGlobalScopes()->count());
        });
    }

    private function asTenantA(callable $callback): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), $callback(...));
    }

    private function expectQueryError(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }
}
