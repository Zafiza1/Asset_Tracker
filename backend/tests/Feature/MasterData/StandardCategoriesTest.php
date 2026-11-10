<?php

namespace Tests\Feature\MasterData;

use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\GenericMaster\Attributes\AttributeDefinitionRules;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StandardCategoriesTest extends TestCase
{
    public function test_a_new_organization_starts_with_the_standard_categories_and_their_fields(): void
    {
        $organization = $this->tenancy()->runAsSystem(fn () => $this->app->make(OrganizationProvisioner::class)
            ->provision(['code' => 'BARU', 'name' => 'Organisasi Baru'], null)['organization']);

        $this->tenancy()->runAsSystem(function () use ($organization) {
            $categories = TenantAssetCategory::query()->where('organization_id', $organization->id)->get()->keyBy('code');
            $this->assertEqualsCanonicalizing(array_keys(StandardCategories::all()), $categories->keys()->all());

            $labels = fn (string $code) => TenantCategoryAttribute::query()->where('category_id', $categories[$code]->id)->where('is_default', true)->pluck('label')->all();
            foreach (['Merek', 'Model / Tipe', 'Catatan', 'Kondisi Aset', 'Satuan', 'Barcode', 'Pemilik', 'Penanggung Jawab', 'Jam Operasi'] as $general) {
                $this->assertContains($general, $labels('AST_INVENTARIS'));
            }
            // Category-specific fields are ordinary (editable, removable) fields, not defaults.
            $specific = fn (string $code) => TenantCategoryAttribute::query()->where('category_id', $categories[$code]->id)->where('is_default', false)->pluck('label')->all();
            $this->assertNotContains('Nomor Polisi', $labels('AST_KENDARAAN'));
            $this->assertContains('Nomor Polisi', $specific('AST_KENDARAAN'));
            $this->assertNotContains('Nomor Polisi', $specific('AST_IT'));

            $condition = TenantCategoryAttribute::query()->where('category_id', $categories['AST_IT']->id)->where('label', 'Kondisi Aset')->firstOrFail();
            $this->assertTrue($condition->is_required);
            $this->assertSame(['Baru', 'Baik', 'Cukup', 'Rusak Ringan', 'Rusak Berat'], array_column($condition->options, 'label'));

            $storage = TenantCategoryAttribute::query()->where('category_id', $categories['AST_IT']->id)->where('label', 'Jenis Penyimpanan')->firstOrFail();
            $this->assertSame(CategoryAttributeService::SECTION_SPECIFIC, $storage->section);
        });
    }

    public function test_specific_fields_can_be_edited_moved_and_removed(): void
    {
        $org = $this->createOrganization('ACME');
        $this->actingAs($org['admin'], 'web');
        $category = $this->tenancy()->runAsSystem(fn () => $this->app->make(TenantCategoryService::class)
            ->installStandard($org['organization']->id)['AST_KENDARAAN']);
        $url = "/api/tenant-masters/categories/{$category->id}/attributes";
        $fields = collect($this->getJson($url)->assertOk()->json('data'));
        $plate = $fields->firstWhere('label', 'Nomor Polisi');
        $this->assertFalse($plate['is_default']);
        $this->assertSame(CategoryAttributeService::SECTION_SPECIFIC, $plate['section']);

        $row = fn (array $f, array $patch = []) => ['id' => $f['id'], 'label' => $f['label'], 'data_type' => $f['data_type'], 'is_required' => $f['is_required'], 'options' => $f['options'], 'section' => $f['section'], ...$patch];
        $color = $fields->firstWhere('label', 'Warna Kendaraan');
        // Plate first, renamed, required and moved to "Informasi Dasar"; the colour field left out (removed).
        $rows = [$row($plate, ['label' => 'Plat Nomor', 'is_required' => true, 'section' => 'basic_information'])];
        foreach ($fields->where('category_id', $category->id) as $f) {
            if ($f['id'] !== $plate['id'] && $f['id'] !== $color['id']) {
                $rows[] = $row($f);
            }
        }
        $saved = collect($this->putJson($url, ['attributes' => $rows])->assertOk()->json('data'));

        $this->assertSame(['Plat Nomor', true, 'basic_information', $plate['key']], [$saved[0]['label'], $saved[0]['is_required'], $saved[0]['section'], $saved[0]['key']]);
        $this->assertNull($saved->firstWhere('label', 'Warna Kendaraan'));
        $this->assertSame('archived', TenantCategoryAttribute::query()->findOrFail($color['id'])->status);
        $this->assertSame($fields->where('is_default', true)->count(), $saved->where('is_default', true)->count());
    }

    public function test_standard_fields_are_valid_and_their_labels_are_unique_within_a_category(): void
    {
        $general = collect(CategoryAttributeService::DEFAULT_ATTRIBUTES)->flatten(1)->pluck('label');
        $rules = $this->app->make(AttributeDefinitionRules::class);

        foreach (StandardCategories::all() as $code => $spec) {
            $labels = $general->merge(array_column($spec['attributes'], 'label'))->map(fn (string $l) => mb_strtolower($l));
            $this->assertSame($labels->count(), $labels->unique()->count(), "Label ganda pada {$code}");

            foreach ($spec['attributes'] as $attribute) {
                [, $errors] = $rules->normalizeOptions($attribute['data_type'], $attribute['options'] ?? null);
                $this->assertSame([], $errors, "{$code}: {$attribute['label']}");
            }
        }
    }

    public function test_status_labels_match_the_default_choices(): void
    {
        $this->assertSame(['Aktif', 'Tidak Aktif', 'Dalam Perbaikan', 'Dipinjamkan', 'Hilang', 'Dihapuskan'], array_values(AssetStatus::LABELS));
    }

    public function test_location_is_required_while_the_serial_number_is_optional(): void
    {
        $org = $this->createOrganization('ACME');
        $masters = $this->createMasterFixtures($org['organization']);
        $this->actingAs($org['admin'], 'web');

        $this->postJson('/api/assets', ['name' => 'Tanpa lokasi', 'category_id' => $masters['category']->id])
            ->assertStatus(422)->assertJsonValidationErrors('location_id', 'error.details.fields');

        $this->postJson('/api/assets', ['name' => 'Tanpa nomor seri', 'category_id' => $masters['category']->id, 'location_id' => $masters['location']->id])
            ->assertCreated()->assertJsonPath('data.serial_number', null)->assertJsonPath('data.status', AssetStatus::IN_USE);
    }

    public function test_condition_is_required_and_moves_along_when_the_category_changes(): void
    {
        $org = $this->createOrganization('ACME');
        $masters = $this->createMasterFixtures($org['organization']);
        $this->actingAs($org['admin'], 'web');
        $from = $this->postJson('/api/tenant-masters/categories', ['code' => 'A', 'name' => 'A'])->assertCreated()->json('data.id');
        $to = $this->postJson('/api/tenant-masters/categories', ['code' => 'B', 'name' => 'B'])->assertCreated()->json('data.id');
        $key = fn (string $category) => collect($this->getJson("/api/tenant-masters/categories/{$category}/attributes")->json('data'))->firstWhere('label', 'Kondisi Aset')['key'];
        $input = ['name' => 'Laptop', 'category_id' => $from, 'location_id' => $masters['location']->id];

        $this->assertErrorCode($this->postJson('/api/assets', $input), 422, 'VALIDATION_FAILED');
        $id = $this->postJson('/api/assets', [...$input, 'attributes' => [$key($from) => 'BARU']])->assertCreated()->json('data.id');

        $values = $this->patchJson("/api/assets/{$id}", ['category_id' => $to])->assertOk()->json('data.attributes');
        $this->assertSame([$key($to) => 'BARU'], $values);
    }

    public function test_an_asset_can_be_marked_lost_and_cannot_then_be_transferred(): void
    {
        $org = $this->createOrganization('ACME');
        $masters = $this->createMasterFixtures($org['organization']);
        $this->actingAs($org['admin'], 'web');
        $id = $this->postJson('/api/assets', ['name' => 'Laptop', 'category_id' => $masters['category']->id, 'location_id' => $masters['location']->id])->assertCreated()->json('data.id');

        $this->postJson('/api/transactions', ['asset_ids' => [$id], 'type' => 'status_change', 'to_status' => AssetStatus::LOST, 'transaction_date' => now()->toDateString()])->assertCreated();
        $this->assertSame(AssetStatus::LOST, Asset::query()->findOrFail($id)->status);

        $other = $this->createMasterFixtures($org['organization'], '2');
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_ids' => [$id], 'type' => 'transfer', 'to_location_id' => $other['location']->id, 'transaction_date' => now()->toDateString()]), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_an_asset_document_can_be_uploaded_downloaded_and_removed(): void
    {
        Storage::fake('local');
        $org = $this->createOrganization('ACME');
        $masters = $this->createMasterFixtures($org['organization']);
        $this->actingAs($org['admin'], 'web');
        $id = $this->postJson('/api/assets', ['name' => 'Laptop', 'category_id' => $masters['category']->id, 'location_id' => $masters['location']->id])->assertCreated()->json('data.id');

        $this->postJson("/api/assets/{$id}/document", ['document' => UploadedFile::fake()->create('skrip.exe', 10)])->assertStatus(422);

        $path = $this->post("/api/assets/{$id}/document", ['document' => UploadedFile::fake()->create('garansi.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.document_name', 'garansi.pdf')->assertJsonPath('data.document_url', "/api/assets/{$id}/document");
        $stored = Asset::query()->findOrFail($id)->document_path;
        Storage::disk('local')->assertExists($stored);

        $download = $this->get($path->json('data.document_url'))->assertOk();
        $this->assertStringContainsString('garansi.pdf', (string) $download->headers->get('Content-Disposition'));

        $this->deleteJson("/api/assets/{$id}/document")->assertOk()->assertJsonPath('data.document_url', null)->assertJsonPath('data.document_name', null);
        Storage::disk('local')->assertMissing($stored);
    }
}
