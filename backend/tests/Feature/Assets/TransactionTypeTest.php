<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The organization's own transaction types (F-07a): types and fields managed by Super Admin and
 * Supervisor only, transactions of those types validated against the fields and numbered with
 * the type's code, isolated per organization.
 */
class TransactionTypeTest extends TestCase
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
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_super_admin_defines_a_type_and_the_definition_is_checked(): void
    {
        $type = $this->mutasi();
        $this->assertSame(['MUT', 'active', 1], [$type['code'], $type['status'], count($type['categories'])]);
        $this->assertSame(['lokasi_tujuan', 'alasan', 'rencana_tiba', 'nilai', 'penerima', 'pihak', 'catatan'], array_column($type['fields'], 'key'));
        $this->assertSame(255, $type['fields'][6]['settings']['max_length']);

        $this->getJson('/api/transaction-types')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.field_types.select', 'Pilihan tunggal');

        $invalid = fn (array $body, string $field) => $this->assertErrorCode($this->postJson('/api/transaction-types', [
            'code' => 'KAL', 'name' => 'Kalibrasi', 'fields' => [], ...$body,
        ])->assertJsonValidationErrors([$field], 'error.details.fields'), 422, 'VALIDATION_FAILED');
        $invalid(['code' => 'TRF'], 'code');   // a system prefix
        $invalid(['code' => 'mut'], 'code');   // taken (case-insensitive)
        $invalid(['fields' => [['key' => 'hasil', 'label' => 'Hasil', 'data_type' => 'select']]], 'fields.0.settings.options');
        $invalid(['fields' => [['key' => 'Bad Key', 'label' => 'X', 'data_type' => 'text']]], 'fields.0.key');
        $invalid(['fields' => [['key' => 'n', 'label' => 'N', 'data_type' => 'number', 'settings' => ['min' => 5, 'max' => 1]]]], 'fields.0.settings.max');
        $invalid(['fields' => [['key' => 'x', 'label' => 'X', 'data_type' => 'script']]], 'fields.0.data_type');
    }

    public function test_only_super_admin_and_supervisor_manage_types(): void
    {
        $type = $this->mutasi();
        $this->actingAs($this->createTenantUser($this->org, ['ADMIN']), 'web');
        $this->getJson("/api/transaction-types/{$type['id']}")->assertOk();
        $this->assertErrorCode($this->postJson('/api/transaction-types', ['code' => 'KAL', 'name' => 'Kalibrasi', 'fields' => []]), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->putJson("/api/transaction-types/{$type['id']}", ['name' => 'X']), 403, 'FORBIDDEN');

        $this->actingAs($this->createTenantUser($this->org, ['SUPERVISOR']), 'web');
        $this->putJson("/api/transaction-types/{$type['id']}", ['name' => 'Mutasi Antar Cabang'])->assertOk()->assertJsonPath('data.name', 'Mutasi Antar Cabang');

        // No other role, default or custom, may be given the permissions (D-27).
        $this->actingAs($this->admin, 'web');
        $this->assertErrorCode($this->postJson('/api/roles', ['code' => 'GUDANG', 'name' => 'Gudang', 'permissions' => ['transaction.view', 'transaction_type.manage']]),
            422, 'TRANSACTION_SETUP_ROLE_ONLY');
    }

    public function test_a_transaction_of_the_type_is_validated_numbered_and_leaves_the_asset_alone(): void
    {
        $type = $this->mutasi();
        $asset = $this->asset();
        $target = $this->postJson('/api/locations', ['name' => 'Gudang Cirebon'])->assertCreated()->json('data.id');
        $values = [
            'lokasi_tujuan' => $target, 'alasan' => 'proyek', 'rencana_tiba' => now()->addDays(3)->toDateString(),
            'nilai' => '1500000.5', 'penerima' => $this->admin->id, 'pihak' => ['name' => 'CV Ekspedisi', 'contact' => '0812-1111'],
        ];

        $failing = fn (array $override, string $field) => $this->custom($type['id'], [$asset], [...$values, ...$override])
            ->assertStatus(422)->assertJsonValidationErrors([$field], 'error.details.fields');
        $failing(['alasan' => null], 'field_values.alasan');                                   // required
        $failing(['alasan' => 'liburan'], 'field_values.alasan');                              // not an option
        $failing(['rencana_tiba' => now()->subDay()->toDateString()], 'field_values.rencana_tiba');   // not before today
        $failing(['nilai' => '1.234'], 'field_values.nilai');                                  // money: 2 decimals
        $failing(['penerima' => (string) Str::uuid()], 'field_values.penerima');               // not a member
        $failing(['lokasi_tujuan' => (string) Str::uuid()], 'field_values.lokasi_tujuan');
        $failing(['bonus' => 'x'], 'field_values');                                            // unknown key

        $row = $this->custom($type['id'], [$asset], $values)->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^MUT-\d{4}-\d{6}$/', $row['transaction_number']);
        $this->assertSame(['custom', 'Mutasi Cabang', 'MUT'], [$row['type'], $row['type_label'], $row['type_definition']['code']]);
        $stored = collect($row['field_values'])->keyBy('key');
        $this->assertSame(['Lokasi tujuan', $target], [$stored['lokasi_tujuan']['label'], $stored['lokasi_tujuan']['value']]);
        $this->assertSame(1500000.5, $stored['nilai']['value']);
        $this->assertNull($stored['catatan']['value']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available')->assertJsonPath('data.location_id', $this->masters['location']->id);

        // Outside-party contact is personal data: hidden without transaction.contact.view.
        $this->actingAs($this->createTenantUser($this->org, [$this->createRole($this->org, 'LIHAT', ['transaction.view'])->code]), 'web');
        $seen = collect($this->getJson("/api/transactions/{$row['id']}")->assertOk()->json('data.field_values'))->firstWhere('key', 'pihak')['value'];
        $this->assertSame(['name' => 'CV Ekspedisi', 'contact' => null], $seen);
    }

    public function test_type_rules_status_categories_and_asset_count(): void
    {
        $type = $this->mutasi(['multi_asset' => false]);
        $asset = $this->asset();
        $minimal = ['lokasi_tujuan' => $this->masters['location']->id, 'alasan' => 'proyek', 'rencana_tiba' => now()->toDateString()];

        $this->custom($type['id'], [$asset, $this->asset()], $minimal)->assertStatus(422)->assertJsonValidationErrors(['asset_ids'], 'error.details.fields');

        $other = $this->createMasterFixtures($this->org, '2')['category']->id;   // not one of the type's categories
        $foreign = $this->postJson('/api/assets', ['name' => 'Mobil', 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $other, 'location_id' => $this->masters['location']->id])->assertCreated()->json('data.id');
        $this->assertErrorCode($this->custom($type['id'], [$foreign], $minimal), 422, 'CATEGORY_NOT_ALLOWED');

        $this->putJson("/api/transaction-types/{$type['id']}", ['status' => 'inactive'])->assertOk();
        $this->assertErrorCode($this->custom($type['id'], [$asset], $minimal), 422, 'TYPE_INACTIVE');
    }

    public function test_fields_keep_their_meaning_and_used_types_are_not_deleted(): void
    {
        $type = $this->mutasi();
        $fields = $type['fields'];
        $asset = $this->asset();
        $row = $this->custom($type['id'], [$asset], ['lokasi_tujuan' => $this->masters['location']->id, 'alasan' => 'proyek',
            'rencana_tiba' => now()->toDateString(), 'catatan' => 'Bawa charger'])->assertCreated()->json('data.id');

        $changed = array_map(fn ($f) => [...array_intersect_key($f, array_flip(['key', 'label', 'data_type', 'is_required'])), 'settings' => (array) $f['settings']], $fields);
        $changed[6]['data_type'] = 'number';
        $this->putJson("/api/transaction-types/{$type['id']}", ['fields' => $changed])->assertStatus(422)->assertJsonValidationErrors(['fields.6.data_type'], 'error.details.fields');

        // Removing a field keeps what was entered before.
        $this->putJson("/api/transaction-types/{$type['id']}", ['fields' => array_slice($changed, 0, 6)])->assertOk()->assertJsonCount(6, 'data.fields');
        $kept = collect($this->getJson("/api/transactions/{$row}")->json('data.field_values'))->firstWhere('key', 'catatan');
        $this->assertSame(['catatan', 'Bawa charger'], [$kept['label'], $kept['value']]);

        $this->assertErrorCode($this->putJson("/api/transaction-types/{$type['id']}", ['code' => 'MTS']), 409, 'TYPE_CODE_LOCKED');
        $this->assertErrorCode($this->deleteJson("/api/transaction-types/{$type['id']}"), 409, 'TYPE_IN_USE');

        $unused = $this->postJson('/api/transaction-types', ['code' => 'OPN', 'name' => 'Stock Opname', 'fields' => []])->assertCreated()->json('data.id');
        $this->deleteJson("/api/transaction-types/{$unused}")->assertNoContent();
    }

    public function test_another_organization_can_neither_see_nor_use_the_type(): void
    {
        $type = $this->mutasi();
        ['organization' => $beta, 'admin' => $betaAdmin] = $this->createOrganization('BETA');
        $betaMasters = $this->createMasterFixtures($beta, '2');
        $this->actingAs($betaAdmin, 'web');

        $this->getJson('/api/transaction-types')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(404, $this->getJson("/api/transaction-types/{$type['id']}")->status());
        $betaAsset = $this->postJson('/api/assets', ['name' => 'Aset Beta', 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $betaMasters['category']->id, 'location_id' => $betaMasters['location']->id])->assertCreated()->json('data.id');
        $this->custom($type['id'], [$betaAsset], [])->assertStatus(422)->assertJsonValidationErrors(['type_definition_id'], 'error.details.fields');
    }

    /** @param array<string, mixed> $override */
    private function mutasi(array $override = []): array
    {
        return $this->postJson('/api/transaction-types', [
            'code' => 'mut', 'name' => 'Mutasi Cabang', 'status' => 'active', 'category_ids' => [$this->masters['category']->id],
            'fields' => [
                ['key' => 'lokasi_tujuan', 'label' => 'Lokasi tujuan', 'data_type' => 'location', 'is_required' => true],
                ['key' => 'alasan', 'label' => 'Alasan mutasi', 'data_type' => 'select', 'is_required' => true,
                    'settings' => ['options' => [['value' => 'proyek', 'label' => 'Kebutuhan proyek'], ['value' => 'tutup', 'label' => 'Penutupan cabang']]]],
                ['key' => 'rencana_tiba', 'label' => 'Rencana tiba', 'data_type' => 'date', 'is_required' => true, 'settings' => ['not_before_today' => true]],
                ['key' => 'nilai', 'label' => 'Nilai dokumen', 'data_type' => 'money'],
                ['key' => 'penerima', 'label' => 'Penerima', 'data_type' => 'user'],
                ['key' => 'pihak', 'label' => 'Ekspedisi', 'data_type' => 'counterparty'],
                ['key' => 'catatan', 'label' => 'Catatan', 'data_type' => 'text'],
            ],
            ...$override,
        ])->assertCreated()->json('data');
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<string>  $assets
     * @param  array<string, mixed>  $values
     */
    private function custom(string $typeId, array $assets, array $values): TestResponse
    {
        return $this->postJson('/api/transactions', [
            ...(count($assets) === 1 ? ['asset_id' => $assets[0]] : ['asset_ids' => $assets]),
            'type' => 'custom', 'type_definition_id' => $typeId, 'field_values' => $values,
        ]);
    }
}
