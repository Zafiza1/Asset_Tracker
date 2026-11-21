<?php

namespace Tests\Feature\Reports;

use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

/**
 * Phase 3 of the Custom Report Designer: design validation against the Field Registry,
 * live-data preview resolution (bindings, formatting, conditions, repeating rows),
 * permission filtering and tenant/data-scope isolation.
 */
class ReportDesignerBindingTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    /** @var array<string, mixed> */
    private array $mastersA;

    private Asset $assetA;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        $this->mastersA = $this->createMasterFixtures($this->orgA);
        $fixture = $this->createAssetFixture($this->orgA, $this->mastersA);
        $this->assetA = $fixture['asset'];
        $this->assetA->forceFill([
            'acquisition_cost' => 1500000,
            'acquisition_date' => '2024-01-15',
        ])->save();
        // asset_number is assigned by a DB trigger on insert; reload to see it.
        $this->assetA->refresh();
        $this->actingAs($this->adminA, 'web');
    }

    public function test_validate_accepts_registry_bindings_and_reports_unknown_ones(): void
    {
        $template = $this->createTemplate('asset', $this->design([
            ['id' => 't1', 'type' => 'dynamic_text', 'props' => ['text' => '{{organization.name}}: {{asset.name}}']],
            ['id' => 't2', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'asset.asset_number', 'label' => 'No'],
                ['key' => 'asset.acquisition_cost', 'label' => 'Nilai'],
            ]]],
        ]));

        $ok = $this->postJson("/api/report-templates/{$template}/validate")->assertOk()->json('data');
        $this->assertTrue($ok['valid']);
        $this->assertSame([], $ok['issues']);

        $bad = $this->createTemplate('asset', $this->design([
            ['id' => 'b1', 'type' => 'dynamic_text', 'props' => ['text' => '{{asset.bogus}}']],
            ['id' => 'b2', 'type' => 'dynamic_text', 'props' => ['text' => '{{asset.name']],
            ['id' => 'b3', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'organization.name', 'label' => 'Org'],
            ]]],
            ['id' => 'b4', 'type' => 'dynamic_text', 'props' => ['text' => 'x', 'visibleWhen' => '{{asset.status} =>']],
        ]));

        $result = $this->postJson("/api/report-templates/{$bad}/validate")->assertOk()->json('data');
        $this->assertFalse($result['valid']);
        $codes = array_column($result['issues'], 'code');
        $this->assertContains('UNKNOWN_BINDING', $codes);
        $this->assertContains('MALFORMED_BINDING', $codes);
        // Context fields are legal in text but not as table columns (row-bound cells).
        $this->assertContains('INVALID_CONDITION', $codes);
    }

    public function test_validate_rejects_foreign_source_fields(): void
    {
        $template = $this->createTemplate('asset_transaction', $this->design([
            ['id' => 'x1', 'type' => 'dynamic_text', 'props' => ['text' => '{{asset.name}}']],
            ['id' => 'x2', 'type' => 'dynamic_text', 'props' => ['text' => '{{transaction.asset_name}}']],
        ]));

        $result = $this->postJson("/api/report-templates/{$template}/validate")->assertOk()->json('data');
        $this->assertFalse($result['valid']);
        $unknown = array_values(array_filter($result['issues'], fn ($i) => $i['code'] === 'UNKNOWN_BINDING'));
        $this->assertCount(1, $unknown);
        $this->assertSame('elements[id=x1].props.text', $unknown[0]['path']);
    }

    public function test_preview_resolves_context_rows_formatting_and_conditions(): void
    {
        $template = $this->createTemplate('asset', $this->design([
            ['id' => 'h1', 'type' => 'dynamic_text', 'props' => ['text' => 'Laporan {{organization.name}}']],
            ['id' => 'h2', 'type' => 'dynamic_text', 'props' => ['text' => 'Aset: {{asset.name}}']],
            ['id' => 'tb', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'asset.asset_number', 'label' => 'No. Aset'],
                ['key' => 'asset.acquisition_cost', 'label' => 'Nilai'],
                ['key' => 'asset.acquisition_date', 'label' => 'Tgl'],
                ['key' => 'asset.status', 'label' => 'Status'],
                ['key' => 'asset.location', 'label' => 'Lokasi'],
            ]]],
            ['id' => 'c1', 'type' => 'dynamic_text', 'props' => [
                'text' => 'Kondisi: {{asset.status}}',
                'visibleWhen' => "{{asset.status}} == 'available'",
            ]],
            ['id' => 'c2', 'type' => 'dynamic_text', 'props' => [
                'text' => 'Tersembunyi',
                'visibleWhen' => '!{{asset.serial_number}}',
            ]],
        ]));

        $data = $this->postJson("/api/report-templates/{$template}/preview", [
            'filters' => ['status' => 'available'],
        ])->assertOk()->json('data');

        $this->assertTrue($data['valid']);
        $this->assertSame('Organisasi ALPHA', $data['context']['organization.name']);
        $this->assertSame(1, $data['total']);
        $this->assertSame('Aset 1', $data['rows'][0]['asset.name']);
        $this->assertSame('Rp 1.500.000', $data['rows'][0]['asset.acquisition_cost']);
        $this->assertSame('15/01/2024', $data['rows'][0]['asset.acquisition_date']);
        $this->assertSame(AssetStatus::LABELS['available'], $data['rows'][0]['asset.status']);
        $this->assertSame('Gudang 1', $data['rows'][0]['asset.location']);

        $elements = collect($data['elements'])->keyBy('id');
        $this->assertSame('Laporan Organisasi ALPHA', $elements['h1']['text']);
        $this->assertSame('Aset: Aset 1', $elements['h2']['text']);
        $this->assertSame('Kondisi: '.AssetStatus::LABELS['available'], $elements['c1']['text']);
        $this->assertTrue($elements['c1']['visible']);
        $this->assertFalse($elements['c2']['visible']); // serial_number is set → !x is false

        $row = $elements['tb']['rows'][0];
        $this->assertSame($this->assetA->asset_number, $row['asset.asset_number']);
        $this->assertSame('Rp 1.500.000', $row['asset.acquisition_cost']);
        $this->assertSame(AssetStatus::LABELS['available'], $row['asset.status']);
    }

    public function test_preview_supports_transaction_source_and_version_snapshot(): void
    {
        $template = $this->createTemplate('asset_transaction', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'transaction.number', 'label' => 'No'],
                ['key' => 'transaction.type', 'label' => 'Jenis'],
                ['key' => 'transaction.asset_name', 'label' => 'Aset'],
                ['key' => 'transaction.to_location', 'label' => 'Lokasi'],
            ]]],
        ]));

        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');
        $this->assertSame(1, $data['total']);
        $cells = $data['elements'][0]['rows'][0];
        $this->assertSame('REG-FIX-1', $cells['transaction.number']);
        $this->assertSame('Penambahan aset', $cells['transaction.type']);
        $this->assertSame('Aset 1', $cells['transaction.asset_name']);
        $this->assertSame('Gudang 1', $cells['transaction.to_location']);
    }

    public function test_preview_shows_department_and_custodian_on_both_sources(): void
    {
        $sari = $this->createTenantUser($this->orgA, [], [], ['name' => 'Sari Dewi']);
        Asset::query()->whereKey($this->assetA->id)->update(['custodian_user_id' => $sari->id, 'department_id' => $this->mastersA['department']->id]);

        $assets = $this->createTemplate('asset', $this->design([
            ['id' => 'tb', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'asset.department', 'label' => 'Departemen'],
                ['key' => 'asset.custodian', 'label' => 'Penanggung jawab'],
            ]]],
        ]));
        $row = $this->postJson("/api/report-templates/{$assets}/preview")->assertOk()->json('data.rows.0');
        $this->assertSame(['Departemen 1', 'Sari Dewi'], [$row['asset.department'], $row['asset.custodian']]);

        $transactions = $this->createTemplate('asset_transaction', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'transaction.asset_department', 'label' => 'Departemen'],
                ['key' => 'transaction.asset_custodian', 'label' => 'Penanggung jawab'],
            ]]],
        ]));
        $cells = $this->postJson("/api/report-templates/{$transactions}/preview")->assertOk()->json('data.elements.0.rows.0');
        $this->assertSame(['Departemen 1', 'Sari Dewi'], [$cells['transaction.asset_department'], $cells['transaction.asset_custodian']]);
    }

    public function test_preview_honours_filters_and_isolated_tenants(): void
    {
        // A second org asset must never leak into ALPHA's preview.
        $mastersB = $this->createMasterFixtures($this->orgB, 'B');
        $this->createAssetFixture($this->orgB, $mastersB, 'B');

        $template = $this->createTemplate('asset', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'asset.name', 'label' => 'Nama'],
            ]]],
        ]));

        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');
        $this->assertSame(1, $data['total']);
        $this->assertSame('Aset 1', $data['rows'][0]['asset.name']);

        // Filters narrow the set: a search that misses returns zero rows.
        $empty = $this->postJson("/api/report-templates/{$template}/preview", [
            'filters' => ['search' => 'tidak-ada'],
        ])->assertOk()->json('data');
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['rows']);

        // BETA cannot see or preview ALPHA's template at all.
        $this->flushSession();
        $this->actingAs($this->adminB, 'web');
        $this->postJson("/api/report-templates/{$template}/preview")->assertNotFound();
        $this->postJson("/api/report-templates/{$template}/validate")->assertNotFound();
    }

    public function test_preview_respects_field_permissions_and_data_scope(): void
    {
        // A location-scoped reporter: sees only assets inside their location subtree.
        $location = $this->mastersA['location'];
        $role = $this->createRole($this->orgA, 'LOC_REPORTER', [
            'organization.view', 'asset.view', 'transaction.view',
            'report.view', 'report.preview', 'report.update',
        ])->code;
        $scoped = $this->createTenantUser($this->orgA, [$role], [['scope_type' => 'location', 'ref_id' => $location->id]]);

        // Asset without a location: invisible to location-scoped users (fail closed).
        $noLocation = (new Asset)->forceFill([
            'organization_id' => $this->orgA->id, 'name' => 'Aset Tanpa Lokasi',
            'category_id' => $this->mastersA['category']->id, 'status' => 'available',
        ]);
        $noLocation->save();

        $template = $this->createTemplate('asset', $this->design([
            ['id' => 't1', 'type' => 'table', 'props' => ['columns' => [
                ['key' => 'asset.name', 'label' => 'Nama'],
            ]]],
        ]));

        $this->flushSession();
        $this->actingAs($scoped, 'web');
        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertSame('Aset 1', $data['rows'][0]['asset.name']);
    }

    public function test_validate_and_preview_require_their_permissions(): void
    {
        $template = $this->createTemplate('asset', $this->design());

        // report.view only: neither endpoint is reachable.
        $viewer = $this->createTenantUser($this->orgA, [$this->viewerRole($this->orgA)]);
        $this->flushSession();
        $this->actingAs($viewer, 'web');
        $this->postJson("/api/report-templates/{$template}/validate")->assertForbidden();
        $this->postJson("/api/report-templates/{$template}/preview")->assertForbidden();

        // report.update without report.preview: validate works, preview does not.
        $designer = $this->createTenantUser($this->orgA, [$this->createRole($this->orgA, 'DESIGNER_ONLY', [
            'organization.view', 'asset.view', 'transaction.view',
            'report.view', 'report.update',
        ])->code]);
        $this->flushSession();
        $this->actingAs($designer, 'web');
        $this->postJson("/api/report-templates/{$template}/validate")->assertOk();
        $this->postJson("/api/report-templates/{$template}/preview")->assertForbidden();
    }

    public function test_preview_does_not_mutate_template_or_versions(): void
    {
        $template = $this->createTemplate('asset', $this->design([
            ['id' => 't1', 'type' => 'dynamic_text', 'props' => ['text' => '{{asset.name}}']],
        ]));
        $before = $this->getJson("/api/report-templates/{$template}")->assertOk()->json('data');

        $this->postJson("/api/report-templates/{$template}/preview")->assertOk();

        $after = $this->getJson("/api/report-templates/{$template}")->assertOk()->json('data');
        $this->assertSame($before['updated_at'], $after['updated_at']);
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['active_version'], $after['active_version']);
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<array<string, mixed>> $elements */
    private function design(array $elements = []): array
    {
        return [
            'schema' => 1,
            'page' => ['size' => 'A4', 'orientation' => 'portrait',
                'marginTop' => 15, 'marginRight' => 15, 'marginBottom' => 15, 'marginLeft' => 15],
            'elements' => $elements === [] ? [[
                'id' => 'd1', 'type' => 'dynamic_text', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10,
                'z' => 1, 'style' => [], 'props' => ['text' => '{{report.title}}'],
            ]] : array_map(fn ($e, $i) => [
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
