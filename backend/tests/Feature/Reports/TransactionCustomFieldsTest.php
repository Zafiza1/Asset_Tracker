<?php

namespace Tests\Feature\Reports;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-07g (D-31): the fields of the organization's own transaction types are bindable in the
 * Report Designer (asset_transaction source) and usable as columns of the
 * custom_transactions dashboard widget — resolved per row from the stored field_values.
 */
class TransactionCustomFieldsTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    /** @var array<string, mixed> */
    private array $type;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');

        $this->type = $this->postJson('/api/transaction-types', [
            'code' => 'mut', 'name' => 'Mutasi Cabang', 'status' => 'active',
            'fields' => [
                ['key' => 'lokasi_tujuan', 'label' => 'Lokasi tujuan', 'data_type' => 'location', 'is_required' => true],
                ['key' => 'alasan', 'label' => 'Alasan mutasi', 'data_type' => 'select', 'is_required' => true,
                    'settings' => ['options' => [['value' => 'proyek', 'label' => 'Kebutuhan proyek'], ['value' => 'tutup', 'label' => 'Penutupan cabang']]]],
                ['key' => 'rencana_tiba', 'label' => 'Rencana tiba', 'data_type' => 'date'],
                ['key' => 'nilai', 'label' => 'Nilai dokumen', 'data_type' => 'money'],
                ['key' => 'catatan', 'label' => 'Catatan', 'data_type' => 'text'],
            ],
        ])->assertCreated()->json('data');

        $this->asset = (new Asset)->forceFill([
            'organization_id' => $this->org->id, 'name' => 'Laptop Uji', 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ]);
        $this->asset->save();
        $target = $this->postJson('/api/locations', ['name' => 'Gudang Cirebon'])->assertCreated()->json('data.id');
        $this->postJson('/api/transactions', [
            'asset_id' => $this->asset->id, 'type' => 'custom', 'type_definition_id' => $this->type['id'],
            'field_values' => [
                'lokasi_tujuan' => $target, 'alasan' => 'proyek',
                'rencana_tiba' => now()->addDays(3)->toDateString(), 'nilai' => '1500000', 'catatan' => 'Pindah proyek',
            ],
        ])->assertCreated();
    }

    public function test_custom_fields_are_offered_in_the_field_catalog(): void
    {
        $data = $this->getJson('/api/report-fields')->assertOk()->json('data');
        $fields = collect($data['asset_transaction'])->keyBy('field_key');

        $this->assertSame('Mutasi Cabang — Alasan mutasi', $fields['transaction.custom.MUT.alasan']['label']);
        $this->assertSame('string', $fields['transaction.custom.MUT.alasan']['data_type']);
        $this->assertSame('transaction.view', $fields['transaction.custom.MUT.alasan']['required_permission']);
        $this->assertSame('currency', $fields['transaction.custom.MUT.nilai']['data_type']);
        $this->assertSame('date', $fields['transaction.custom.MUT.rencana_tiba']['data_type']);
    }

    public function test_report_template_binds_and_resolves_custom_fields(): void
    {
        $template = $this->createTemplate('asset_transaction', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'transaction.custom.MUT.alasan', 'label' => 'Alasan'],
                ['key' => 'transaction.custom.MUT.rencana_tiba', 'label' => 'Rencana'],
                ['key' => 'transaction.custom.MUT.nilai', 'label' => 'Nilai'],
                ['key' => 'transaction.custom.MUT.catatan', 'label' => 'Catatan'],
                ['key' => 'transaction.custom.MUT.lokasi_tujuan', 'label' => 'Lokasi'],
            ]]],
        ]));

        $this->postJson("/api/report-templates/{$template}/validate")->assertOk()
            ->assertJsonPath('data.valid', true);

        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');
        $this->assertSame(1, $data['total']);
        $cells = $data['elements'][0]['rows'][0];
        $this->assertSame('Kebutuhan proyek', $cells['transaction.custom.MUT.alasan']);
        $this->assertSame(now()->addDays(3)->format('d/m/Y'), $cells['transaction.custom.MUT.rencana_tiba']);
        $this->assertSame('Rp 1.500.000', $cells['transaction.custom.MUT.nilai']);
        $this->assertSame('Pindah proyek', $cells['transaction.custom.MUT.catatan']);
        $this->assertSame('Gudang Cirebon', $cells['transaction.custom.MUT.lokasi_tujuan']);
    }

    public function test_widget_lists_custom_transactions_with_field_columns(): void
    {
        $dashboard = new Dashboard(['name' => 'Uji', 'visibility' => 'organization', 'status' => Dashboard::STATUS_ACTIVE]);
        $dashboard->organization_id = $this->org->id;
        $dashboard->dashboard_type = 'custom';
        $dashboard->created_by = $this->admin->id;
        $dashboard->save();

        $catalog = WidgetCatalog::query()->where('widget_key', 'custom_transactions')->firstOrFail();
        $widget = new DashboardWidget;
        $widget->forceFill([
            'organization_id' => $this->org->id, 'dashboard_id' => $dashboard->id,
            'widget_catalog_id' => $catalog->id, 'configuration' => [],
            'layout' => $catalog->default_layout, 'sort_order' => 0, 'is_visible' => true,
        ])->save();

        $data = $this->getJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}/data")->assertOk()->json('data');
        $keys = array_column($data['columns'], 'key');
        $this->assertContains('field.'.$this->type['id'].'.alasan', $keys);
        $this->assertContains('field.'.$this->type['id'].'.lokasi_tujuan', $keys);

        $row = collect($data['rows'])->firstWhere('type', 'Mutasi Cabang');
        $this->assertNotNull($row);
        $this->assertSame('Kebutuhan proyek', $row['field.'.$this->type['id'].'.alasan']);
        $this->assertSame('Gudang Cirebon', $row['field.'.$this->type['id'].'.lokasi_tujuan']);
        $this->assertSame('Rp 1.500.000', $row['field.'.$this->type['id'].'.nilai']);
    }

    public function test_fields_of_other_types_do_not_leak_into_a_row(): void
    {
        // A second type reusing the same field key must not show its value under MUT's column.
        $this->postJson('/api/transaction-types', [
            'code' => 'kal', 'name' => 'Kalibrasi', 'status' => 'active',
            'fields' => [['key' => 'alasan', 'label' => 'Alasan kalibrasi', 'data_type' => 'text']],
        ])->assertCreated();

        $asset2 = (new Asset)->forceFill([
            'organization_id' => $this->org->id, 'name' => 'Mesin Uji', 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ]);
        $asset2->save();
        $kal = TransactionTypeDefinition::query()->where('code', 'KAL')->firstOrFail();
        $this->postJson('/api/transactions', [
            'asset_id' => $asset2->id, 'type' => 'custom', 'type_definition_id' => $kal->id,
            'field_values' => ['alasan' => 'kalibrasi tahunan'],
        ])->assertCreated();

        $template = $this->createTemplate('asset_transaction', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'transaction.custom.MUT.alasan', 'label' => 'Alasan MUT'],
                ['key' => 'transaction.custom.KAL.alasan', 'label' => 'Alasan KAL'],
            ]]],
        ]));

        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');
        $this->assertSame(2, $data['total']);
        $kalRow = collect($data['elements'][0]['rows'])->first(fn ($r) => $r['transaction.custom.KAL.alasan'] === 'kalibrasi tahunan');
        $mutRow = collect($data['elements'][0]['rows'])->first(fn ($r) => $r['transaction.custom.MUT.alasan'] === 'Kebutuhan proyek');
        $this->assertNotNull($kalRow);
        $this->assertNotNull($mutRow);
        // The KAL row shows nothing under MUT's column and vice versa.
        $this->assertSame('-', $kalRow['transaction.custom.MUT.alasan']);
        $this->assertSame('-', $mutRow['transaction.custom.KAL.alasan']);
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<array<string, mixed>> $elements */
    private function design(array $elements = []): array
    {
        return [
            'schema' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                'marginTop' => 15, 'marginRight' => 15, 'marginBottom' => 15, 'marginLeft' => 15],
            'elements' => array_map(fn ($e, $i) => [
                'x' => 10, 'y' => 10 + $i * 20, 'w' => 80, 'h' => 10, 'z' => $i + 1,
                'style' => [], 'type' => 'dynamic_text', 'props' => [],
                ...$e,
            ], $elements, array_keys($elements)),
        ];
    }

    private function createTemplate(string $source, array $design): string
    {
        return $this->postJson('/api/report-templates', [
            'code' => 'RPT-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Template Uji',
            'data_source' => $source,
            'design' => $design,
        ])->assertCreated()->json('data.id');
    }
}
