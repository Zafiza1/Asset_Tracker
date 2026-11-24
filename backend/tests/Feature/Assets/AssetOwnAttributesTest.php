<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

/**
 * Fields of one asset beyond its category: optional category fields it does not use are left
 * out (RAM on a keyboard of the IT category), and fields only it has are added. The category
 * does not change.
 */
class AssetOwnAttributesTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private string $category;

    private string $location;

    /** @var array<string, string> label => key */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ACME');
        $this->location = $this->createMasterFixtures($this->org)['location']->id;
        $this->actingAs($this->admin, 'web');
        // A category made in the app gets the default fields: Kondisi Aset (required), Merek, ...
        $this->category = $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'IT'])->assertCreated()->json('data.id');
        $this->keys = collect($this->getJson("/api/tenant-masters/categories/{$this->category}/attributes")->json('data'))->pluck('key', 'label')->all();
    }

    public function test_a_keyboard_leaves_out_a_field_and_has_its_own(): void
    {
        $asset = $this->postJson('/api/assets', $this->input([
            'attributes' => [$this->keys['Kondisi Aset'] => 'BARU', $this->keys['Merek'] => 'Logitech'],
            'hidden_attributes' => [$this->keys['Merek']],
            'extra_attributes' => [
                ['label' => 'Jenis Konektor', 'data_type' => 'string', 'value' => ' USB '],
                ['label' => 'Jumlah Tombol', 'data_type' => 'integer', 'value' => 104],
            ],
        ]))->assertCreated()->json('data');

        // A field left out keeps no value.
        $this->assertSame([$this->keys['Kondisi Aset'] => 'BARU'], $asset['attributes']);
        $this->assertSame([$this->keys['Merek']], $asset['hidden_attributes']);
        // jsonb keeps no key order, hence assertEquals.
        $this->assertEquals([
            ['label' => 'Jenis Konektor', 'data_type' => 'string', 'value' => 'USB'],
            ['label' => 'Jumlah Tombol', 'data_type' => 'integer', 'value' => 104],
        ], $asset['extra_attributes']);

        // Brought back, and one own field removed.
        $updated = $this->patchJson("/api/assets/{$asset['id']}", [
            'hidden_attributes' => [],
            'attributes' => [$this->keys['Merek'] => 'Logitech'],
            'extra_attributes' => [['label' => 'Jenis Konektor', 'data_type' => 'string', 'value' => 'Bluetooth']],
        ])->assertOk()->json('data');
        $this->assertSame('Logitech', $updated['attributes'][$this->keys['Merek']]);
        $this->assertSame([], $updated['hidden_attributes']);
        $this->assertEquals([['label' => 'Jenis Konektor', 'data_type' => 'string', 'value' => 'Bluetooth']], $updated['extra_attributes']);

        // Other changes leave them as they are.
        $this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Keyboard Logitech K120'])->assertOk()
            ->assertJsonPath('data.extra_attributes.0.value', 'Bluetooth');
    }

    public function test_a_required_field_cannot_be_left_out(): void
    {
        $this->postJson('/api/assets', $this->input([
            'attributes' => [$this->keys['Kondisi Aset'] => 'BARU'],
            'hidden_attributes' => [$this->keys['Kondisi Aset']],
        ]))->assertStatus(422)->assertJsonValidationErrors('hidden_attributes', 'error.details.fields');
    }

    public function test_an_own_field_needs_a_free_name_a_known_type_and_a_value_of_that_type(): void
    {
        $post = fn (array $extras, array $hidden = []) => $this->postJson('/api/assets', $this->input([
            'attributes' => [$this->keys['Kondisi Aset'] => 'BARU'], 'hidden_attributes' => $hidden, 'extra_attributes' => $extras,
        ]));

        // The name of a category field the asset uses, or of another own field.
        $post([['label' => 'merek', 'data_type' => 'string', 'value' => 'X']])->assertStatus(422)
            ->assertJsonValidationErrors('extra_attributes.0.label', 'error.details.fields');
        $post([['label' => 'Warna', 'data_type' => 'string', 'value' => 'Hitam'], ['label' => 'warna', 'data_type' => 'string', 'value' => 'Putih']])
            ->assertStatus(422)->assertJsonValidationErrors('extra_attributes.1.label', 'error.details.fields');
        // A field left out frees its name.
        $post([['label' => 'Merek', 'data_type' => 'string', 'value' => 'Rexus']], [$this->keys['Merek']])->assertCreated();

        $post([['label' => 'Jumlah Tombol', 'data_type' => 'integer', 'value' => 'banyak']])->assertStatus(422)
            ->assertJsonValidationErrors('extra_attributes.0.value', 'error.details.fields');
        $post([['label' => 'Pilihan', 'data_type' => 'enum', 'value' => 'A']])->assertStatus(422)
            ->assertJsonValidationErrors('extra_attributes.0.data_type', 'error.details.fields');
        $post([['label' => '', 'data_type' => 'string', 'value' => 'A']])->assertStatus(422)
            ->assertJsonValidationErrors('extra_attributes.0.label', 'error.details.fields');
    }

    public function test_an_approver_sees_readable_input_without_the_technical_field_names(): void
    {
        $id = $this->postJson('/api/assets', $this->input(['attributes' => [$this->keys['Kondisi Aset'] => 'BARU']]))->assertCreated()->json('data.id');

        $this->actingAs($this->createTenantUser($this->org, [$this->createRole($this->org, 'EDITOR', ['asset.view', 'asset.update'])->code]), 'web');
        $approval = $this->patchJson("/api/assets/{$id}", [
            'name' => 'Keyboard Logitech',
            'hidden_attributes' => [$this->keys['Merek']],
            'extra_attributes' => [['label' => 'Jenis Konektor', 'data_type' => 'string', 'value' => 'USB']],
        ])->assertStatus(202)->json('data.id');

        // As the category attribute values: the approver decides on the asset's main data.
        $this->actingAs($this->admin, 'web');
        $this->assertSame(['Nama'], array_column($this->getJson("/api/approvals/{$approval}")->assertOk()->json('data.details'), 'label'));

        // Once approved, both are applied.
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();
        $asset = $this->getJson("/api/assets/{$id}")->assertOk()->json('data');
        $this->assertSame([$this->keys['Merek']], $asset['hidden_attributes']);
        $this->assertSame('USB', $asset['extra_attributes'][0]['value']);
    }

    /** @param array<string, mixed> $extra */
    private function input(array $extra): array
    {
        return ['name' => 'Keyboard', 'category_id' => $this->category, 'location_id' => $this->location, ...$extra];
    }
}
