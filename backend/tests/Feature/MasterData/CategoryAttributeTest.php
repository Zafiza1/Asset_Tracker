<?php

namespace Tests\Feature\MasterData;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Custom fields per asset category and the asset values that follow them. */
class CategoryAttributeTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_fields_are_created_with_generated_keys_and_inherited_by_subcategories(): void
    {
        $vehicles = $this->category('KEND', 'Kendaraan');
        $cars = $this->category('MOBIL', 'Mobil', $vehicles);

        $plate = $this->field($vehicles, ['label' => 'Nomor Polisi', 'data_type' => 'string', 'is_required' => true])
            ->assertCreated()->assertJsonPath('data.key', 'nomor_polisi')->json('data');
        $this->field($cars, ['label' => 'Jumlah Kursi', 'data_type' => 'integer'])->assertCreated()->assertJsonPath('data.key', 'jumlah_kursi');
        $this->field($cars, ['label' => 'Bahan Bakar', 'data_type' => 'enum', 'options' => [['label' => 'Bensin'], ['label' => 'Solar']]])
            ->assertCreated()
            ->assertJsonPath('data.options.0.value', 'BENSIN')->assertJsonPath('data.options.0.label', 'Bensin');

        $carFields = $this->getJson("/api/tenant-masters/categories/{$cars->id}/attributes")->assertOk()
            ->assertJsonPath('data.0.category.code', 'KEND');
        $this->assertSame(['Nomor Polisi', 'Jumlah Kursi', 'Bahan Bakar'], $this->customFields($carFields)->pluck('label')->all());
        // The default fields come from the top-level category only, once.
        $this->assertSame($this->defaultLabels(), collect($carFields->json('data'))->where('is_default', true)->pluck('label')->all());
        $this->assertSame(['KEND'], collect($carFields->json('data'))->where('is_default', true)->pluck('category.code')->unique()->values()->all());
        $vehicleFields = $this->getJson("/api/tenant-masters/categories/{$vehicles->id}/attributes")->assertOk();
        $this->assertCount(1, $this->customFields($vehicleFields));

        $listed = collect($this->getJson('/api/tenant-masters/categories?all=1')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(1 + count($this->defaultLabels()), $listed['KEND']['attributes_count']);
        $this->assertSame(2, $listed['MOBIL']['attributes_count']);

        // Same label within the lineage is refused; another branch may reuse it (with its own key).
        $this->assertErrorCode($this->field($cars, ['label' => 'nomor polisi', 'data_type' => 'string']), 422, 'VALIDATION_FAILED');
        $other = $this->category('ALAT', 'Alat Berat');
        $this->field($other, ['label' => 'Nomor Polisi', 'data_type' => 'string'])->assertCreated()->assertJsonPath('data.key', 'nomor_polisi_2');

        // Key, type and category never change.
        try {
            DB::transaction(fn () => DB::table('tenant_category_attributes')->where('id', $plate['id'])->update(['data_type' => 'integer']));
            $this->fail('Expected the database to reject the type change');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_field_definitions_are_validated(): void
    {
        $category = $this->masters['category'];
        $this->assertErrorCode($this->field($category, ['label' => 'Warna', 'data_type' => 'multi_enum', 'options' => [['label' => 'Merah']]]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->field($category, ['label' => 'Warna', 'data_type' => 'enum']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->field($category, ['label' => 'Warna', 'data_type' => 'string', 'options' => [['label' => 'Merah']]]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->field($category, ['label' => '', 'data_type' => 'string']), 422, 'VALIDATION_FAILED');
    }

    public function test_asset_values_follow_the_category_fields(): void
    {
        $vehicles = $this->category('KEND', 'Kendaraan');
        $cars = $this->category('MOBIL', 'Mobil', $vehicles);
        $this->field($vehicles, ['label' => 'Nomor Polisi', 'data_type' => 'string', 'is_required' => true]);
        $this->field($cars, ['label' => 'Jumlah Kursi', 'data_type' => 'integer']);
        $this->field($cars, ['label' => 'Tanggal Pajak', 'data_type' => 'date']);

        // Required and typed values are enforced, also for inherited fields.
        $response = $this->postJson('/api/assets', ['name' => 'Avanza', 'category_id' => $cars->id, 'attributes' => ['jumlah_kursi' => 'tujuh']]);
        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('error.details.fields', fn ($fields) => isset($fields['attributes.nomor_polisi'], $fields['attributes.jumlah_kursi']));

        $asset = $this->postJson('/api/assets', ['name' => 'Avanza', 'category_id' => $cars->id, 'attributes' => [
            'nomor_polisi' => ' B 1234 XY ', 'jumlah_kursi' => 7, 'tanggal_pajak' => null, 'tidak_dikenal' => 'x',
        ]])->assertCreated()->assertJsonPath('data.attributes', self::same(['nomor_polisi' => 'B 1234 XY', 'jumlah_kursi' => 7]))->json('data');

        $fields = collect($this->getJson("/api/assets/{$asset['id']}")->assertOk()->json('attribute_fields'));
        $this->assertSame(['nomor_polisi', 'jumlah_kursi', 'tanggal_pajak'], $fields->where('is_default', false)->pluck('key')->values()->all());

        // Editing other data does not touch (or revalidate) the values.
        $this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Avanza Veloz'])->assertOk()
            ->assertJsonPath('data.attributes.nomor_polisi', 'B 1234 XY');

        // Partial update merges; null clears.
        $this->patchJson("/api/assets/{$asset['id']}", ['attributes' => ['jumlah_kursi' => null, 'tanggal_pajak' => '2027-01-31']])->assertOk()
            ->assertJsonPath('data.attributes', self::same(['nomor_polisi' => 'B 1234 XY', 'tanggal_pajak' => '2027-01-31']));

        // Moving to the parent category drops the values of fields that no longer apply.
        $this->patchJson("/api/assets/{$asset['id']}", ['category_id' => $vehicles->id])->assertOk()
            ->assertJsonPath('data.attributes', self::same(['nomor_polisi' => 'B 1234 XY']));

        // A category with a required field the asset lacks blocks the move until it is filled.
        $other = $this->category('ALAT', 'Alat Berat');
        $this->field($other, ['label' => 'Kapasitas', 'data_type' => 'decimal', 'is_required' => true]);
        $this->assertErrorCode($this->patchJson("/api/assets/{$asset['id']}", ['category_id' => $other->id]), 422, 'VALIDATION_FAILED');
        $this->patchJson("/api/assets/{$asset['id']}", ['category_id' => $other->id, 'attributes' => ['kapasitas' => 2.5]])->assertOk()
            ->assertJsonPath('data.attributes', self::same(['kapasitas' => 2.5]));
    }

    public function test_updating_and_removing_fields(): void
    {
        $category = $this->masters['category'];
        $field = $this->field($category, ['label' => 'Kondisi Fisik', 'data_type' => 'enum', 'options' => [['label' => 'Baik'], ['label' => 'Rusak']]])->json('data');

        // Renaming an option keeps its value; new options get a value from their label.
        $this->patchJson("/api/category-attributes/{$field['id']}", [
            'label' => 'Kondisi', 'is_required' => true,
            'options' => [['value' => 'BAIK', 'label' => 'Baik sekali'], ['value' => 'RUSAK', 'label' => 'Rusak'], ['label' => 'Rusak ringan']],
        ])->assertOk()
            ->assertJsonPath('data.label', 'Kondisi')
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.options.*.value', ['BAIK', 'RUSAK', 'RUSAK_RINGAN']);

        $asset = $this->postJson('/api/assets', ['name' => 'Meja', 'category_id' => $category->id, 'attributes' => ['kondisi_fisik' => 'BAIK']])
            ->assertCreated()->json('data');

        // Removing archives the field: it disappears from the category, values are dropped on the next save.
        $this->deleteJson("/api/category-attributes/{$field['id']}")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson("/api/tenant-masters/categories/{$category->id}/attributes")->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/assets/{$asset['id']}", ['attributes' => []])->assertOk()->assertJsonPath('data.attributes', self::same([]));
        $this->assertErrorCode($this->patchJson("/api/category-attributes/{$field['id']}", ['label' => 'X']), 409, 'ALREADY_ARCHIVED');

        // The key of a removed field is never reused.
        $this->field($category, ['label' => 'Kondisi Fisik', 'data_type' => 'string'])->assertCreated()->assertJsonPath('data.key', 'kondisi_fisik_2');
    }

    public function test_the_whole_list_is_saved_at_once(): void
    {
        $vehicles = $this->category('KEND', 'Kendaraan');
        $cars = $this->category('MOBIL', 'Mobil', $vehicles);
        $this->field($vehicles, ['label' => 'Nomor Polisi', 'data_type' => 'string']);
        $sync = fn (array $rows) => $this->putJson("/api/tenant-masters/categories/{$cars->id}/attributes", ['attributes' => $rows]);

        // Several new fields in one save; the response is the effective list (inherited first).
        $saved = $this->customFields($sync([
            ['label' => 'Jumlah Kursi', 'data_type' => 'integer', 'is_required' => true],
            ['label' => 'Bahan Bakar', 'data_type' => 'enum', 'options' => [['label' => 'Bensin'], ['label' => 'Solar']]],
            ['label' => 'Warna', 'data_type' => 'string'],
        ])->assertOk());
        $this->assertSame(['Nomor Polisi', 'Jumlah Kursi', 'Bahan Bakar', 'Warna'], $saved->pluck('label')->all());
        [, $seats, $fuel, $color] = $saved->all();

        // Every problem is reported per row, and nothing is saved.
        $response = $sync([
            ['id' => $seats['id'], 'label' => 'Jumlah Kursi', 'data_type' => 'decimal'],
            ['label' => 'Nomor polisi', 'data_type' => 'string'],
            ['label' => 'Warna', 'data_type' => 'string'],
            ['label' => 'warna', 'data_type' => 'string'],
            ['label' => 'Tipe', 'data_type' => 'enum'],
        ]);
        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED');
        $this->assertEqualsCanonicalizing(
            ['attributes.0.data_type', 'attributes.1.label', 'attributes.3.label', 'attributes.4.options'],
            array_keys($response->json('error.details.fields')),
        );
        $this->assertCount(4, $this->customFields($this->getJson("/api/tenant-masters/categories/{$cars->id}/attributes")));

        // Update, reorder, add, and remove (left out) in one save; keys stay.
        $updated = $this->customFields($sync([
            ['id' => $fuel['id'], 'label' => 'Jenis BBM', 'data_type' => 'enum', 'options' => [...$fuel['options'], ['label' => 'Listrik']]],
            ['id' => $seats['id'], 'label' => 'Jumlah Kursi', 'data_type' => 'integer', 'is_required' => false],
            ['label' => 'Transmisi', 'data_type' => 'string'],
        ])->assertOk());
        $this->assertSame(['Nomor Polisi', 'Jenis BBM', 'Jumlah Kursi', 'Transmisi'], $updated->pluck('label')->all());
        $this->assertSame('bahan_bakar', $updated[1]['key']);
        $this->assertSame('LISTRIK', $updated[1]['options'][2]['value']);
        $this->assertFalse($updated[2]['is_required']);
        $this->assertSame('archived', TenantCategoryAttribute::query()->findOrFail($color['id'])->status);

        // An empty list removes all own fields; inherited ones (custom and default) stay.
        $remaining = $sync([])->assertOk();
        $this->assertSame(['Nomor Polisi'], $this->customFields($remaining)->pluck('label')->all());
        $this->assertCount(count($this->defaultLabels()), collect($remaining->json('data'))->where('is_default', true));
    }

    public function test_viewer_cannot_manage_fields_and_other_tenants_fields_are_invisible(): void
    {
        $viewer = $this->createTenantUser($this->org, [$this->viewerRole($this->org)]);
        $category = $this->masters['category'];
        $this->field($category, ['label' => 'Merek', 'data_type' => 'string'])->assertCreated();

        $this->actingAs($viewer, 'web');
        $this->getJson("/api/tenant-masters/categories/{$category->id}/attributes")->assertOk()->assertJsonCount(1, 'data');
        $this->assertErrorCode($this->field($category, ['label' => 'Tipe', 'data_type' => 'string']), 403, 'FORBIDDEN');

        ['organization' => $orgB] = $this->createOrganization('BETA');
        $mastersB = $this->createMasterFixtures($orgB, 'B');
        $this->assertSame(0, TenantCategoryAttribute::query()->where('category_id', $mastersB['category']->id)->count());
    }

    /**
     * Matches values regardless of key order (jsonb does not keep it).
     *
     * @param  array<string, mixed>  $expected
     */
    private static function same(array $expected): \Closure
    {
        return function (array $actual) use ($expected) {
            ksort($expected);
            ksort($actual);

            return $actual === $expected;
        };
    }

    /** @param array<string, mixed> $data */
    private function field(TenantAssetCategory $category, array $data)
    {
        return $this->postJson("/api/tenant-masters/categories/{$category->id}/attributes", $data);
    }

    /** The fields defined by users (not the defaults every top-level category gets), in order. */
    private function customFields(TestResponse $response): Collection
    {
        return collect($response->json('data'))->where('is_default', false)->values();
    }

    /** @return list<string> */
    private function defaultLabels(): array
    {
        return collect(CategoryAttributeService::DEFAULT_ATTRIBUTES)->flatten(1)->pluck('label')->all();
    }

    private function category(string $code, string $name, ?TenantAssetCategory $parent = null): TenantAssetCategory
    {
        $id = $this->postJson('/api/tenant-masters/categories', ['code' => $code, 'name' => $name, 'parent_id' => $parent?->id])->assertCreated()->json('data.id');

        return TenantAssetCategory::query()->findOrFail($id);
    }
}
