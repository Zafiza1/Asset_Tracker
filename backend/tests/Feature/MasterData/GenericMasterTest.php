<?php

namespace Tests\Feature\MasterData;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\Identity\Models\User;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Platform-side Generic Master: drafts, release, immutability, deprecation, versioning. */
class GenericMasterTest extends TestCase
{
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformAdmin = $this->createPlatformAdmin();
        $this->actingAs($this->platformAdmin, 'web');
    }

    public function test_seeded_catalog_has_released_immutable_versions_with_hash(): void
    {
        $versions = GenericDefinitionVersion::query()->with('definition')->get();

        $this->assertGreaterThanOrEqual(9, $versions->count());
        foreach ($versions as $version) {
            $this->assertSame(GenericDefinitionVersion::RELEASED, $version->status);
            $this->assertSame($this->app->make(SchemaResolver::class)->genericHash($version), $version->schema_hash, $version->definition->code);
        }
    }

    public function test_definition_lifecycle_from_draft_to_release(): void
    {
        $definition = $this->createDefinition();
        $draftId = $definition['versions'][0]['id'];
        $this->assertSame('draft', $definition['versions'][0]['status']);

        $this->postJson("/api/platform/generic-masters/versions/{$draftId}/attributes", [
            'key' => 'kapasitas_tangki', 'label' => 'Kapasitas Tangki', 'data_type' => 'decimal',
            'validation' => ['min' => 0, 'decimals' => 1], 'default_value' => 10.5,
            'overridable' => ['label', 'validation'], 'is_searchable' => true,
        ])->assertCreated()->assertJsonPath('data.overridable', ['label', 'validation']);

        // Release needs a changelog.
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/versions/{$draftId}/release"), 422, 'VALIDATION_FAILED');
        $this->patchJson("/api/platform/generic-masters/versions/{$draftId}", ['changelog' => 'Versi awal.'])->assertOk();

        $this->postJson("/api/platform/generic-masters/versions/{$draftId}/release")
            ->assertOk()->assertJsonPath('data.status', 'released');

        $version = GenericDefinitionVersion::query()->findOrFail($draftId);
        $this->assertNotNull($version->released_at);
        $this->assertSame($this->platformAdmin->id, $version->released_by);
        $this->assertSame(64, strlen($version->schema_hash));
        $this->assertTrue(AuditLog::query()->where('action', 'generic_version.released')->where('entity_id', $draftId)->whereNull('organization_id')->exists());
    }

    public function test_attribute_definitions_are_validated_semantically(): void
    {
        $draftId = $this->createDefinition()['versions'][0]['id'];
        $url = "/api/platform/generic-masters/versions/{$draftId}/attributes";

        $this->postJson($url, ['key' => 'a', 'label' => 'A', 'data_type' => 'enum'])->assertStatus(422)->assertJsonPath('error.details.fields.options.0', fn ($m) => $m !== null);
        $this->postJson($url, ['key' => 'b', 'label' => 'B', 'data_type' => 'integer', 'validation' => ['max_length' => 5]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['validation']]]]);
        $this->postJson($url, ['key' => 'c', 'label' => 'C', 'data_type' => 'integer', 'validation' => ['min' => 10], 'default_value' => 5])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['default_value']]]]);
        $this->postJson($url, ['key' => 'd', 'label' => 'D', 'data_type' => 'string', 'overridable' => ['data_type']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['overridable']]]]);
        $this->postJson($url, ['key' => 'serial_number', 'label' => 'Serial', 'data_type' => 'string'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['key']]]]);
        $this->postJson($url, ['key' => 'Bad-Key', 'label' => 'X', 'data_type' => 'string'])->assertStatus(422);

        $this->assertSame(0, GenericAttributeDefinition::query()->where('definition_version_id', $draftId)->count());
    }

    public function test_released_version_is_immutable_through_the_api(): void
    {
        $version = $this->releasedVersion('GEN-KENDARAAN-RODA4');
        $attribute = $version->attributeDefinitions()->firstOrFail();

        $this->assertErrorCode($this->patchJson("/api/platform/generic-masters/versions/{$version->id}", ['changelog' => 'x']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->deleteJson("/api/platform/generic-masters/versions/{$version->id}"), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/versions/{$version->id}/attributes",
            ['key' => 'baru', 'label' => 'Baru', 'data_type' => 'string']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->patchJson("/api/platform/generic-masters/attributes/{$attribute->id}", ['label' => 'Diubah']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->deleteJson("/api/platform/generic-masters/attributes/{$attribute->id}"), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/versions/{$version->id}/release"), 409, 'VERSION_IMMUTABLE');

        $this->assertSame($attribute->label, $attribute->fresh()->label);
        $this->assertSame($version->schema_hash, $version->fresh()->schema_hash);
    }

    public function test_released_version_is_immutable_in_the_database(): void
    {
        $version = $this->releasedVersion('GEN-LAPTOP');
        $attribute = $version->attributeDefinitions()->firstOrFail();

        $this->expectQueryError('P0001', fn () => DB::table('generic_asset_definition_versions')->where('id', $version->id)->update(['changelog' => 'tampered']));
        $this->expectQueryError('P0001', fn () => DB::table('generic_asset_definition_versions')->where('id', $version->id)->update(['status' => 'draft']));
        $this->expectQueryError('P0001', fn () => DB::table('generic_asset_definition_versions')->where('id', $version->id)->delete());
        $this->expectQueryError('P0001', fn () => DB::table('generic_attribute_definitions')->where('id', $attribute->id)->update(['is_required' => true]));
        $this->expectQueryError('P0001', fn () => DB::table('generic_attribute_definitions')->where('id', $attribute->id)->delete());
        $this->expectQueryError('P0001', fn () => DB::table('generic_attribute_definitions')->insert([
            'id' => (string) Str::uuid7(), 'definition_version_id' => $version->id, 'key' => 'sisipan', 'label' => 'X', 'data_type' => 'string',
        ]));

        // The only allowed change: released -> deprecated.
        $this->assertSame(1, DB::table('generic_asset_definition_versions')->where('id', $version->id)
            ->update(['status' => 'deprecated', 'deprecated_at' => now()]));
    }

    public function test_new_draft_copies_previous_attributes_and_requires_migration_notes(): void
    {
        $definition = GenericAssetDefinition::query()->where('code', 'GEN-TABUNG-GAS')->firstOrFail();
        $v1 = $definition->versions()->firstOrFail();

        $draft = $this->postJson("/api/platform/generic-masters/definitions/{$definition->id}/versions")
            ->assertCreated()->assertJsonPath('data.version_number', 2)->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertSame($v1->attributeDefinitions()->pluck('key')->all(), array_column($draft['attributes'], 'key'));
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/definitions/{$definition->id}/versions"), 409, 'DRAFT_EXISTS');

        // Editing the copy never touches v1.
        $copied = GenericAttributeDefinition::query()->where('definition_version_id', $draft['id'])->where('key', 'kapasitas')->firstOrFail();
        $this->patchJson("/api/platform/generic-masters/attributes/{$copied->id}", ['label' => 'Kapasitas Isi'])->assertOk();
        $this->assertSame('Kapasitas', $v1->attributeDefinitions()->where('key', 'kapasitas')->value('label'));

        $this->patchJson("/api/platform/generic-masters/versions/{$draft['id']}", ['changelog' => 'Ubah label.'])->assertOk();
        $this->postJson("/api/platform/generic-masters/versions/{$draft['id']}/release")
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['migration_notes']]]]);
        $this->patchJson("/api/platform/generic-masters/versions/{$draft['id']}", ['migration_notes' => 'Tidak ada perubahan struktur.'])->assertOk();
        $this->postJson("/api/platform/generic-masters/versions/{$draft['id']}/release")->assertOk();

        $this->assertSame(GenericDefinitionVersion::RELEASED, $v1->fresh()->status);
        $this->assertSame(2, $definition->currentVersion()->first()->version_number);
    }

    public function test_draft_can_be_discarded_but_released_cannot(): void
    {
        $definition = GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->firstOrFail();
        $draftId = $this->postJson("/api/platform/generic-masters/definitions/{$definition->id}/versions")->json('data.id');

        $this->deleteJson("/api/platform/generic-masters/versions/{$draftId}")->assertNoContent();

        $this->assertNull(GenericDefinitionVersion::query()->find($draftId));
        $this->assertSame(0, GenericAttributeDefinition::query()->where('definition_version_id', $draftId)->count());
    }

    public function test_deprecate_requires_release_permission_and_released_status(): void
    {
        $version = $this->releasedVersion('GEN-ALAT-MEDIS');

        $this->postJson("/api/platform/generic-masters/versions/{$version->id}/deprecate")->assertOk()->assertJsonPath('data.status', 'deprecated');
        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/versions/{$version->id}/deprecate"), 409, 'VERSION_NOT_RELEASED');
    }

    public function test_platform_user_without_role_cannot_manage_generic_master(): void
    {
        $this->actingAs($this->createPlatformAdmin(withRole: false), 'web');

        $this->assertErrorCode($this->getJson('/api/platform/generic-masters/definitions'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson('/api/platform/generic-masters/categories', ['code' => 'X', 'name' => 'X']), 403, 'FORBIDDEN');
    }

    public function test_category_in_use_cannot_be_archived(): void
    {
        $category = GenericAssetCategory::query()->where('code', 'KENDARAAN')->firstOrFail();

        $this->assertErrorCode($this->postJson("/api/platform/generic-masters/categories/{$category->id}/archive"), 409, 'CATEGORY_IN_USE');

        $empty = $this->postJson('/api/platform/generic-masters/categories', ['code' => 'kosong', 'name' => 'Kosong'])
            ->assertCreated()->assertJsonPath('data.code', 'KOSONG')->json('data.id');
        $this->postJson("/api/platform/generic-masters/categories/{$empty}/archive", ['reason' => 'Tidak dipakai'])
            ->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_reference_data_is_managed_by_the_platform(): void
    {
        $unit = $this->postJson('/api/platform/generic-masters/units', ['code' => 'psi', 'name' => 'Pound per square inch', 'symbol' => 'psi'])
            ->assertCreated()->json('data');
        $this->patchJson("/api/platform/generic-masters/units/{$unit['id']}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->getJson('/api/platform/generic-masters/transaction-types')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/platform/generic-masters/conditions')->assertOk()->assertJsonPath('data.0.code', 'BAIK');
    }

    /** @return array<string, mixed> */
    private function createDefinition(): array
    {
        $categoryId = GenericAssetCategory::query()->where('code', 'KENDARAAN')->value('id');

        return $this->postJson('/api/platform/generic-masters/definitions', [
            'code' => 'gen-truk', 'name' => 'Truk', 'category_id' => $categoryId,
        ])->assertCreated()->assertJsonPath('data.code', 'GEN-TRUK')->json('data');
    }

    private function releasedVersion(string $code): GenericDefinitionVersion
    {
        return GenericAssetDefinition::query()->where('code', $code)->firstOrFail()->versions()->where('status', 'released')->firstOrFail();
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
