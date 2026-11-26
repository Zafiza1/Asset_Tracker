<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Domain\TenantMaster\StandardCategories;
use Tests\TestCase;

/** PT SIG / PT CDO: a cylinder's Business Unit follows its organization and its status its transactions. */
class AssetBusinessUnitTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private TenantAssetCategory $cylinder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edition.locations' => false, 'edition.custodians' => false]);
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('PTCDO');
        $this->org->forceFill(['name' => 'PT CDO'])->save();
        $this->cylinder = app(TenantCategoryService::class)->installOne($this->org->id, StandardCategories::CYLINDER, StandardCategories::cylinder());
        $this->actingAs($this->admin);
    }

    public function test_business_unit_is_the_organization_whatever_is_sent(): void
    {
        $created = $this->postJson('/api/assets', [
            'name' => 'Oksigen', 'serial_number' => 'MF-1', 'category_id' => $this->cylinder->id,
            'status' => 'under_maintenance', 'attributes' => $this->values(['Business Unit' => 'PT_SIG']),
        ])->assertCreated()->json('data');

        $this->assertSame('PT_CDO', $created['attributes'][$this->key('Business Unit')]);
        $this->assertSame('available', $created['status'], 'a new cylinder is Di Gudang; its status is not chosen');

        $this->patchJson("/api/assets/{$created['id']}", ['attributes' => [$this->key('Business Unit') => 'PT_SIG']])
            ->assertOk()->assertJsonPath('data.attributes.'.$this->key('Business Unit'), 'PT_CDO');
    }

    public function test_a_missing_business_unit_is_filled_in_on_the_next_save(): void
    {
        $values = $this->values();
        unset($values[$this->key('Business Unit')]);
        $asset = (new Asset)->forceFill(['organization_id' => $this->org->id, 'name' => 'Oksigen', 'serial_number' => 'MF-2',
            'category_id' => $this->cylinder->id, 'status' => 'available', 'attribute_values' => $values]);
        $asset->save();

        $this->patchJson("/api/assets/{$asset->id}", ['name' => 'Oksigen Medis'])
            ->assertOk()->assertJsonPath('data.attributes.'.$this->key('Business Unit'), 'PT_CDO');
    }

    public function test_an_organization_not_named_after_an_option_chooses_it_by_hand(): void
    {
        $this->org->forceFill(['name' => 'Organisasi Lain'])->save();

        $this->postJson('/api/assets', [
            'name' => 'Oksigen', 'serial_number' => 'MF-3', 'category_id' => $this->cylinder->id,
            'attributes' => $this->values(['Business Unit' => 'PT_SIG']),
        ])->assertCreated()->assertJsonPath('data.attributes.'.$this->key('Business Unit'), 'PT_SIG');
    }

    private function key(string $label): string
    {
        return TenantCategoryAttribute::query()->where('category_id', $this->cylinder->id)->where('label', $label)->value('key');
    }

    /**
     * Valid values of the cylinder's required fields, by key.
     *
     * @param  array<string, mixed>  $override  by label
     * @return array<string, mixed>
     */
    private function values(array $override = []): array
    {
        $values = [...['Business Unit' => 'PT_CDO', 'Warna' => 'Putih', 'Bahan' => 'Baja', 'Volume Tabung' => 40, 'Satuan Volume' => 'LITER',
            'Jenis Gas' => 'Oksigen', 'Grade Gas' => 'Medis', 'Jenis Tabung' => 'Besar', 'UOM' => 'TBG'], ...$override];

        return collect($values)->mapWithKeys(fn ($value, $label) => [$this->key($label) => $value])->all();
    }
}
