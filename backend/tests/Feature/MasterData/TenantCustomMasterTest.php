<?php

namespace Tests\Feature\MasterData;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Services\GenericCatalogService;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use App\Domain\TenantMaster\Schema\CanonicalJson;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tenant Custom Master: adoption, tenant attributes, overrides, release with a frozen
 * effective schema, and explicit upgrades to newer generic versions.
 */
class TenantCustomMasterTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private GenericAssetDefinition $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->vehicle = GenericAssetDefinition::query()->where('code', 'GEN-KENDARAAN-RODA4')->firstOrFail();
        $this->actingAs($this->admin, 'web');
    }

    public function test_adoption_creates_definition_with_draft_pinned_to_the_released_generic_version(): void
    {
        $definition = $this->adopt();

        $this->assertSame($this->vehicle->id, $definition['generic_definition_id']);
        $this->assertNull($definition['current_version']);
        $this->assertSame(1, $definition['draft_version']['version_number']);

        $version = TenantDefinitionVersion::query()->findOrFail($definition['draft_version']['id']);
        $this->assertSame($this->vehicle->versions()->where('status', 'released')->value('id'), $version->generic_definition_version_id);
        $this->assertSame($this->org->id, $version->organization_id);
    }

    public function test_cannot_adopt_draft_or_unknown_generic_versions(): void
    {
        $draftId = $this->app->make(GenericCatalogService::class)->createDraft($this->vehicle)->id;
        $this->actingAs($this->admin, 'web');

        $this->assertErrorCode($this->postJson('/api/asset-definitions', [
            'generic_definition_id' => $this->vehicle->id, 'generic_version_id' => $draftId, 'code' => 'MOBIL', 'name' => 'Mobil',
        ]), 422, 'INVALID_GENERIC_VERSION');
        $this->assertErrorCode($this->postJson('/api/asset-definitions', [
            'generic_definition_id' => '00000000-0000-0000-0000-000000000000', 'code' => 'MOBIL', 'name' => 'Mobil',
        ]), 422, 'INVALID_REFERENCE');
    }

    public function test_tenant_category_must_cover_the_generic_category(): void
    {
        $it = $this->postJson('/api/tenant-masters/categories', [
            'code' => 'LAPTOP_DIREKSI', 'name' => 'Laptop Direksi',
            'generic_category_id' => GenericAssetCategory::query()->where('code', 'PERANGKAT_IT')->value('id'),
        ])->assertCreated()->json('data.id');

        $this->assertErrorCode($this->postJson('/api/asset-definitions', [
            'generic_definition_id' => $this->vehicle->id, 'code' => 'MOBIL', 'name' => 'Mobil', 'tenant_category_id' => $it,
        ]), 422, 'CATEGORY_MISMATCH');
    }

    public function test_full_customization_and_release_freezes_the_effective_schema(): void
    {
        $versionId = $this->adopt()['draft_version']['id'];

        $this->postJson("/api/asset-definition-versions/{$versionId}/attributes", [
            'key' => 'kode_kartu_bbm', 'label' => 'Kode Kartu BBM', 'data_type' => 'string', 'is_required' => true,
            'validation' => ['max_length' => 20],
        ])->assertCreated()->assertJsonPath('data.is_required', true)->assertJsonPath('data.is_searchable', false);
        $this->putJson("/api/asset-definition-versions/{$versionId}/overrides", ['overrides' => [
            ['generic_attribute_key' => 'jenis_bahan_bakar', 'overrides' => ['options' => ['SOLAR', 'BENSIN'], 'required' => true, 'label' => 'BBM']],
            ['generic_attribute_key' => 'tahun_pembuatan', 'overrides' => ['validation' => ['min' => 2015]]],
        ]])->assertOk()->assertJsonCount(2, 'data.overrides');

        $preview = $this->getJson("/api/asset-definition-versions/{$versionId}/effective-schema")->assertOk();
        $this->assertSame([], $preview->json('meta.issues'));
        $this->assertFalse($preview->json('meta.frozen'));

        $released = $this->postJson("/api/asset-definition-versions/{$versionId}/release")->assertOk()
            ->assertJsonPath('data.status', 'released')->json('data');
        $this->assertSame($preview->json('meta.schema_hash'), $released['schema_hash']);

        $attributes = collect($released['effective_schema']['attributes'])->keyBy('key');
        $this->assertSame('BBM', $attributes['jenis_bahan_bakar']['label']);
        $this->assertTrue($attributes['jenis_bahan_bakar']['required']);
        $this->assertSame(['BENSIN', 'SOLAR'], array_column($attributes['jenis_bahan_bakar']['options'], 'value'));
        $this->assertSame(['label', 'options', 'required'], $attributes['jenis_bahan_bakar']['overridden']);
        $this->assertSame(2015, $attributes['tahun_pembuatan']['validation']['min']);
        $this->assertSame(2100, $attributes['tahun_pembuatan']['validation']['max']);
        $this->assertSame('tenant', $attributes['kode_kartu_bbm']['source']);
        $this->assertSame('generic', $attributes['nomor_polisi']['source']);
        $this->assertSame($this->vehicle->code, $released['effective_schema']['generic']['definition_code']);

        // Generic Master untouched.
        $fuel = GenericAttributeDefinition::query()->where('key', 'jenis_bahan_bakar')->firstOrFail();
        $this->assertSame('Jenis Bahan Bakar', $fuel->label);
        $this->assertFalse($fuel->is_required);
        $this->assertCount(4, $fuel->options);
    }

    public function test_effective_schema_hash_is_deterministic(): void
    {
        $versionId = $this->adopt()['draft_version']['id'];
        $this->putJson("/api/asset-definition-versions/{$versionId}/overrides", ['overrides' => [
            ['generic_attribute_key' => 'merek', 'overrides' => ['required' => true]],
        ]])->assertOk();

        $first = $this->getJson("/api/asset-definition-versions/{$versionId}/effective-schema")->json('meta.schema_hash');
        $second = $this->getJson("/api/asset-definition-versions/{$versionId}/effective-schema")->json('meta.schema_hash');
        $this->assertSame($first, $second);

        $this->postJson("/api/asset-definition-versions/{$versionId}/release")->assertOk();
        $stored = TenantDefinitionVersion::query()->findOrFail($versionId);
        // The frozen jsonb (keys reordered by PostgreSQL) still hashes to the stored hash.
        $this->assertSame($stored->schema_hash, CanonicalJson::hash($stored->effective_schema));
        $this->assertSame($first, $stored->schema_hash);
        $resolved = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => $this->app->make(SchemaResolver::class)->resolve($stored));
        $this->assertSame($stored->schema_hash, $resolved->hash);
    }

    public function test_overrides_outside_the_allowlist_or_loosening_are_rejected(): void
    {
        $versionId = $this->adopt()['draft_version']['id'];
        $url = "/api/asset-definition-versions/{$versionId}/overrides";

        foreach ([
            ['generic_attribute_key' => 'nomor_polisi', 'overrides' => ['data_type' => 'integer']],
            ['generic_attribute_key' => 'nomor_polisi', 'overrides' => ['required' => false]],
            ['generic_attribute_key' => 'nomor_polisi', 'overrides' => ['validation' => ['max_length' => 5]]], // not overridable for this attribute
            ['generic_attribute_key' => 'jenis_bahan_bakar', 'overrides' => ['options' => ['AVTUR']]],
            ['generic_attribute_key' => 'tahun_pembuatan', 'overrides' => ['validation' => ['max' => 3000]]],
            ['generic_attribute_key' => 'tidak_ada', 'overrides' => ['label' => 'X']],
        ] as $entry) {
            $this->assertErrorCode($this->putJson($url, ['overrides' => [$entry]]), 422, 'INVALID_OVERRIDE');
        }

        $this->assertSame(0, DB::table('tenant_attribute_overrides')->count());
    }

    public function test_tenant_attribute_keys_cannot_shadow_generic_or_core_keys(): void
    {
        $versionId = $this->adopt()['draft_version']['id'];
        $url = "/api/asset-definition-versions/{$versionId}/attributes";

        $this->postJson($url, ['key' => 'nomor_polisi', 'label' => 'X', 'data_type' => 'string'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['key']]]]);
        $this->postJson($url, ['key' => 'asset_number', 'label' => 'X', 'data_type' => 'string'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['key']]]]);
    }

    public function test_released_tenant_version_is_immutable(): void
    {
        $versionId = $this->releasedVersionId();
        $attributeId = DB::table('tenant_attribute_definitions')->where('tenant_definition_version_id', $versionId)->value('id');

        $this->assertErrorCode($this->patchJson("/api/asset-definition-versions/{$versionId}", ['changelog' => 'x']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->putJson("/api/asset-definition-versions/{$versionId}/overrides", ['overrides' => []]), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->postJson("/api/asset-definition-versions/{$versionId}/attributes", ['key' => 'x', 'label' => 'X', 'data_type' => 'string']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->patchJson("/api/asset-definition-attributes/{$attributeId}", ['label' => 'Y']), 409, 'VERSION_IMMUTABLE');
        $this->assertErrorCode($this->deleteJson("/api/asset-definition-versions/{$versionId}"), 409, 'VERSION_IMMUTABLE');

        // Database layer, independent of the service.
        $this->expectQueryError('P0001', fn () => DB::table('tenant_asset_definition_versions')->where('id', $versionId)->update(['effective_schema' => '{}']));
        $this->expectQueryError('P0001', fn () => DB::table('tenant_attribute_definitions')->where('id', $attributeId)->update(['label' => 'Y']));
        $this->expectQueryError('P0001', fn () => DB::table('tenant_attribute_overrides')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->org->id,
            'tenant_definition_version_id' => $versionId, 'generic_attribute_key' => 'merek', 'overrides' => '{}',
        ]));
    }

    public function test_new_generic_release_does_not_change_tenant_versions_until_explicit_upgrade(): void
    {
        $versionId = $this->releasedVersionId();
        $tenantVersion = TenantDefinitionVersion::query()->findOrFail($versionId);
        $hashBefore = $tenantVersion->schema_hash;
        $genericV1 = $tenantVersion->generic_definition_version_id;

        $genericV2 = $this->releaseGenericV2();

        $tenantVersion->refresh();
        $this->assertSame($hashBefore, $tenantVersion->schema_hash);
        $this->assertSame($genericV1, $tenantVersion->generic_definition_version_id);
        $this->assertNotContains('nomor_kir', array_column($tenantVersion->effective_schema['attributes'], 'key'));

        // A new draft keeps v1 unless the upgrade is chosen explicitly.
        $definitionId = $tenantVersion->tenant_definition_id;
        $draft = $this->postJson("/api/asset-definitions/{$definitionId}/versions")->assertCreated()->json('data');
        $this->assertSame($genericV1, $draft['generic_definition_version_id']);
        $this->assertCount(1, $draft['attributes']);

        $this->patchJson("/api/asset-definition-versions/{$draft['id']}", ['generic_definition_version_id' => $genericV2, 'changelog' => 'Upgrade ke Generic v2.'])
            ->assertOk()->assertJsonPath('data.generic_version.version_number', 2);
        $released = $this->postJson("/api/asset-definition-versions/{$draft['id']}/release")->assertOk()->json('data');

        $this->assertContains('nomor_kir', array_column($released['effective_schema']['attributes'], 'key'));
        $this->assertNotSame($hashBefore, $released['schema_hash']);
        $this->assertSame($hashBefore, TenantDefinitionVersion::query()->findOrFail($versionId)->schema_hash);
    }

    public function test_release_is_blocked_when_the_pinned_generic_version_was_deprecated(): void
    {
        $versionId = $this->adopt()['draft_version']['id'];
        $this->vehicle->versions()->where('version_number', 1)->firstOrFail()
            ->forceFill(['status' => 'deprecated', 'deprecated_at' => now()])->save();

        $response = $this->postJson("/api/asset-definition-versions/{$versionId}/release");

        $this->assertErrorCode($response, 422, 'SCHEMA_INVALID');
        $this->assertNotEmpty($response->json('error.details.issues'));
        $this->assertSame('draft', TenantDefinitionVersion::query()->findOrFail($versionId)->status);
    }

    public function test_viewer_can_read_but_not_change_tenant_masters(): void
    {
        $definitionId = $this->adopt()['id'];
        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');

        $this->getJson('/api/asset-definitions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/asset-definitions/{$definitionId}")->assertOk();
        $this->getJson('/api/generic-masters/definitions')->assertOk();
        $this->assertErrorCode($this->postJson('/api/asset-definitions', ['generic_definition_id' => $this->vehicle->id, 'code' => 'X', 'name' => 'X']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->patchJson("/api/asset-definitions/{$definitionId}", ['name' => 'X']), 403, 'FORBIDDEN');
    }

    /** @return array<string, mixed> */
    private function adopt(): array
    {
        return $this->postJson('/api/asset-definitions', [
            'generic_definition_id' => $this->vehicle->id,
            'code' => 'mobil-direksi',
            'name' => 'Mobil Operasional Direksi',
        ])->assertCreated()->assertJsonPath('data.code', 'MOBIL-DIREKSI')->json('data');
    }

    private function releasedVersionId(): string
    {
        $versionId = $this->adopt()['draft_version']['id'];
        $this->postJson("/api/asset-definition-versions/{$versionId}/attributes", ['key' => 'kode_kartu_bbm', 'label' => 'Kode Kartu BBM', 'data_type' => 'string'])->assertCreated();
        $this->postJson("/api/asset-definition-versions/{$versionId}/release")->assertOk();

        return $versionId;
    }

    /** Platform releases v2 of the vehicle definition with one extra attribute; returns its id. */
    private function releaseGenericV2(): string
    {
        // The Generic Master has no API since the platform console was removed; it changes through the service.
        $catalog = $this->app->make(GenericCatalogService::class);
        $v2 = $catalog->createDraft($this->vehicle);
        $catalog->addAttribute($v2, ['key' => 'nomor_kir', 'label' => 'Nomor KIR', 'data_type' => 'string']);
        $catalog->updateDraft($v2, ['changelog' => 'Tambah nomor KIR.', 'migration_notes' => 'Atribut opsional baru.']);
        $catalog->release($v2->refresh());

        $this->assertSame(GenericDefinitionVersion::RELEASED, GenericDefinitionVersion::query()->findOrFail($v2->id)->status);

        return $v2->id;
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
