<?php

namespace Tests\Feature\MasterData;

use App\Domain\Shared\Tenancy\TenancyState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migration 2026_11_23_000400: the former default text field "Penanggung Jawab" of organizations
 * that already had it. The migration drops and recreates a trigger, which only the table owner
 * may do, so this test runs (and rolls back) on the owner connection, as `migrate` does.
 */
class RetireCustodianTextFieldTest extends TestCase
{
    protected $connectionsToTransact = ['pgsql_owner'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('pgsql_owner');
        // Writes the test's context into the owner session too (it was opened before the hook).
        $this->tenancy()->enter(TenancyState::system());
    }

    public function test_the_field_is_archived_unless_an_asset_filled_it_in(): void
    {
        [$kept, $asset] = $this->formerField($this->createOrganization('ACME'));
        [$archived] = $this->formerField($this->createOrganization('BETA'));
        $this->tenancy()->runAsSystem(fn () => DB::table('assets')->where('id', $asset)
            ->update(['attribute_values' => json_encode(['penanggung_jawab' => 'Pak Budi'])]));

        $this->tenancy()->runAsSystem(fn () => (require database_path('migrations/2026_11_23_000400_retire_default_custodian_text_field.php'))->up());

        $this->tenancy()->runAsSystem(function () use ($kept, $archived, $asset) {
            $row = fn (string $id) => (array) DB::table('tenant_category_attributes')->where('id', $id)->first(['label', 'is_default', 'status']);
            // Filled in: an ordinary field that keeps its values, renamed so it is not mistaken for the asset's own.
            $this->assertSame(['label' => 'Penanggung Jawab (lama)', 'is_default' => false, 'status' => 'active'], $row($kept));
            $this->assertSame('Pak Budi', json_decode(DB::table('assets')->where('id', $asset)->value('attribute_values'), true)['penanggung_jawab']);
            // Never filled in: archived, as the former Barcode field was.
            $this->assertSame(['label' => 'Penanggung Jawab', 'is_default' => true, 'status' => 'archived'], $row($archived));
        });
        // The trigger that keeps is_default immutable is back.
        $this->assertTrue(DB::table('pg_trigger')->where('tgname', 'tenant_category_attributes_default_immutable')->exists());
    }

    /**
     * An organization's category with the field as organizations created before 2026-11-23 have
     * it, and one asset in that category.
     *
     * @param  array<string, mixed>  $org
     * @return array{0: string, 1: string} field id, asset id
     */
    private function formerField(array $org): array
    {
        $masters = $this->createMasterFixtures($org['organization']);
        $this->actingAs($org['admin'], 'web');
        $asset = $this->postJson('/api/assets', ['name' => 'Laptop', 'category_id' => $masters['category']->id, 'location_id' => $masters['location']->id])
            ->assertCreated()->json('data.id');
        $id = (string) Str::uuid();
        $this->tenancy()->runAsSystem(fn () => DB::table('tenant_category_attributes')->insert([
            'id' => $id, 'organization_id' => $org['organization']->id, 'category_id' => $masters['category']->id,
            'key' => 'penanggung_jawab', 'label' => 'Penanggung Jawab', 'data_type' => 'string', 'is_default' => true,
            'section' => 'location_ownership', 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return [$id, $asset];
    }
}
