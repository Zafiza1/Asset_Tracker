<?php

namespace Tests\Feature\MasterData;

use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Seeded Generic Master: released versions carry their hash and stay immutable in the database. */
class GenericMasterTest extends TestCase
{
    public function test_seeded_catalog_has_released_immutable_versions_with_hash(): void
    {
        $versions = GenericDefinitionVersion::query()->with('definition')->get();

        $this->assertGreaterThanOrEqual(9, $versions->count());
        foreach ($versions as $version) {
            $this->assertSame(GenericDefinitionVersion::RELEASED, $version->status);
            $this->assertSame($this->app->make(SchemaResolver::class)->genericHash($version), $version->schema_hash, $version->definition->code);
        }
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
