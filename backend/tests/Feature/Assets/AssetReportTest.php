<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Asset Reports container: filtered asset register with totals, exported as PDF or Excel. */
class AssetReportTest extends TestCase
{
    private Organization $org;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($admin, 'web');
    }

    public function test_report_lists_filtered_assets_with_totals(): void
    {
        $it = $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->json('data.id');
        $this->asset(['name' => 'Laptop', 'category_id' => $it, 'acquisition_cost' => 10000000, 'acquisition_date' => '2024-03-01']);
        $this->asset(['name' => 'Server', 'category_id' => $it, 'acquisition_cost' => 50000000, 'status' => 'in_use', 'acquisition_date' => '2022-06-01']);
        $this->asset(['name' => 'Genset', 'acquisition_cost' => 7000000]);
        $retired = $this->asset(['name' => 'Arsip', 'category_id' => $it, 'acquisition_cost' => 999]);
        $this->postJson("/api/assets/{$retired}/archive")->assertOk();

        // Another tenant's data never appears.
        ['organization' => $orgB] = $this->createOrganization('BETA');
        $this->createAssetFixture($orgB, $this->createMasterFixtures($orgB, 'B'));

        $all = $this->getJson('/api/reports/assets')->assertOk()->json();
        $this->assertSame(3, $all['summary']['total']);
        $this->assertEquals(67000000, (float) $all['summary']['total_value']);
        $this->assertSame(['Genset', 'Laptop', 'Server'], collect($all['data'])->pluck('name')->sort()->values()->all());

        $it2024 = $this->getJson("/api/reports/assets?category_ids={$it}&acquired_from=2024-01-01")->assertOk()->json();
        $this->assertSame(['Laptop'], array_column($it2024['data'], 'name'));
        $this->assertSame(1, $it2024['summary']['total']);
        $this->assertEquals(10000000, (float) $it2024['summary']['total_value']);

        $withArchived = $this->getJson("/api/reports/assets?category_ids={$it}&archived=all")->assertOk()->json('summary');
        $this->assertSame(3, $withArchived['total']);
        $this->assertSame(1, $withArchived['archived']);
        $this->assertSame(['Perangkat IT' => 3], array_column($withArchived['by_category'], 'count', 'name'));

        $this->getJson('/api/reports/assets?acquired_from=2024-01-01&acquired_to=2023-01-01')->assertStatus(422);
    }

    public function test_excel_export_contains_the_filtered_rows_and_total(): void
    {
        $this->asset(['name' => 'Laptop', 'acquisition_cost' => 10000000, 'serial_number' => '00123']);
        $this->asset(['name' => 'Genset', 'acquisition_cost' => 7000000, 'status' => 'under_maintenance']);

        $response = $this->get('/api/reports/assets/export?format=xlsx&status=under_maintenance')->assertOk();
        $this->assertStringContainsString('laporan_aset_', $response->headers->get('Content-Disposition'));

        $book = IOFactory::load($response->getFile()->getPathname());
        $values = collect($book->getSheetByName('Laporan Aset')->toArray())->flatten()->filter()->values()->all();
        // Worksheets reference their workbook: free them, the suite runs within PHP's memory limit.
        $book->disconnectWorksheets();
        @unlink($response->getFile()->getPathname());
        $this->assertContains('Genset', $values);
        $this->assertNotContains('Laptop', $values);
        $this->assertContains('Status: Dalam Perbaikan', $values);
        $this->assertContains('Total: 1 aset', $values);

        $this->assertSame(1, AuditLog::query()->where('action', 'report.exported')->count());
    }

    public function test_pdf_export_returns_a_pdf_document(): void
    {
        $this->asset(['name' => 'Laptop', 'acquisition_cost' => 10000000]);

        $response = $this->get('/api/reports/assets/export?format=pdf')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** The PDF is drawn row by row: hundreds of assets must fit well within PHP's default 128M. */
    public function test_pdf_export_of_many_assets_stays_within_memory(): void
    {
        $base = Asset::query()->findOrFail($this->asset(['name' => 'Laptop', 'acquisition_cost' => 1000]));
        for ($i = 2; $i <= 300; $i++) {
            $base->replicate()->forceFill(['asset_number' => sprintf('AST-T-%06d', $i), 'serial_number' => "SN-{$i}", 'name' => "Laptop Dell Latitude 5440 Unit Kantor Pusat {$i}"])->save();
        }

        $before = memory_get_usage(true);
        $response = $this->get('/api/reports/assets/export?format=pdf')->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertLessThan(48 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_export_rejects_an_unknown_format(): void
    {
        $this->getJson('/api/reports/assets/export?format=csv')->assertStatus(422);
    }

    public function test_report_and_export_require_report_permissions(): void
    {
        // Read-only role: report.view without report.export.
        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');
        $this->getJson('/api/reports/assets')->assertOk();
        $this->assertErrorCode($this->getJson('/api/reports/assets/export?format=pdf'), 403, 'FORBIDDEN');

        $this->flushSession();
        $this->actingAs($this->createTenantUser($this->org, []), 'web');
        $this->assertErrorCode($this->getJson('/api/reports/assets'), 403, 'FORBIDDEN');
    }

    /** @param array<string, mixed> $data */
    private function asset(array $data): string
    {
        $this->relaxDefaultCondition();

        return $this->postJson('/api/assets', [
            'status' => 'available',
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ])->assertCreated()->json('data.id');
    }
}
