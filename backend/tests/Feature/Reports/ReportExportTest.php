<?php

namespace Tests\Feature\Reports;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\ReportDesigner\Models\ReportExport;
use App\Domain\ReportDesigner\Services\ReportDataResolver;
use App\Domain\ReportDesigner\Services\ReportExportService;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Running a published template (F-05): filters, live preview of the active version, PDF and
 * Excel files in the export history, downloads, visibility, expiry.
 */
class ReportExportTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_run_page_filters_preview_and_pdf_export(): void
    {
        $laptop = $this->asset('Laptop');
        $this->asset('Printer');
        $this->out($laptop);
        $template = $this->published('outgoing');

        $filters = $this->getJson("/api/report-templates/{$template}/filters")->assertOk()->json('data');
        $this->assertSame(['type' => 'outgoing'], $filters['defaults']);
        $this->assertSame(ReportDataResolver::MAX_ROWS, $filters['max_rows']);
        $this->assertContains('not_returned', array_column($filters['fields'], 'key'));
        $reasons = array_column(collect($filters['fields'])->firstWhere('key', 'reason_code')['options'], 'value');
        $this->assertSame(count($reasons), count(array_unique($reasons)));   // "Lainnya" once

        // The run preview uses the active version and the template's default filter.
        $preview = $this->postJson("/api/report-templates/{$template}/run-preview")->assertOk()->json('data');
        $this->assertSame(1, $preview['total']);
        $this->assertSame(['Jenis: Transaksi keluar'], $preview['filter_description']);

        $export = $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'pdf'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^RPT-\d{4}-\d{6}$/', $export['export_number']);
        $this->assertSame(['pdf', 'completed', true, 1], [$export['format'], $export['status'], $export['downloadable'], $export['row_count']]);
        $this->assertSame(['Jenis: Transaksi keluar'], $export['filter_description']);
        $this->assertSame(1, $export['version_number']);

        $file = $this->get("/api/report-exports/{$export['id']}/download")->assertOk();
        $this->assertStringStartsWith('%PDF', $file->streamedContent());
        $this->assertStringContainsString('.pdf', (string) $file->headers->get('content-disposition'));

        $this->getJson("/api/report-exports?template_id={$template}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.export_number', $export['export_number']);
        $this->assertTrue(AuditLog::query()->where('action', 'report.exported')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'report.downloaded')->exists());
    }

    public function test_excel_export_holds_the_table_data_as_text(): void
    {
        // Text that a spreadsheet would run as a formula stays text.
        $asset = $this->asset('=HYPERLINK("http://evil","klik")');
        $this->out($asset, ['counterparty_name' => '+CV Servis']);
        $template = $this->published('outgoing');

        $export = $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'xlsx', 'filters' => ['date_from' => now()->subDay()->toDateString()]])
            ->assertCreated()->json('data');
        $this->assertContains('Tanggal: '.now()->subDay()->format('d/m/Y').' s.d. …', $export['filter_description']);

        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $this->get("/api/report-exports/{$export['id']}/download")->assertOk()->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);
        $cells = collect($sheet->toArray(null, false, false))->flatten()->filter()->values();
        $this->assertContains('=HYPERLINK("http://evil","klik")', $cells->all());
        $this->assertContains('+CV Servis', $cells->all());
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $this->assertNotSame('f', $sheet->getCell($coordinate)->getDataType(), "{$coordinate} is a formula");
        }
    }

    public function test_draft_templates_too_many_rows_and_bad_filters_are_refused(): void
    {
        $draft = $this->postJson('/api/report-templates/from-sample', ['sample' => 'outgoing'])->assertCreated()->json('data.id');
        $this->assertErrorCode($this->postJson("/api/report-templates/{$draft}/exports", ['format' => 'pdf']), 409, 'TEMPLATE_NOT_PUBLISHED');
        $this->assertErrorCode($this->getJson("/api/report-templates/{$draft}/filters"), 409, 'TEMPLATE_NOT_PUBLISHED');

        $template = $this->published('outgoing');
        $this->assertErrorCode($this->postJson("/api/report-templates/{$template}/exports", ['format' => 'docx']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->postJson("/api/report-templates/{$template}/exports", [
            'format' => 'pdf', 'filters' => ['date_from' => '2026-11-10', 'date_to' => '2026-11-01'],
        ]), 422, 'VALIDATION_FAILED');

        // One row over the synchronous limit (D-13) is refused before any file is written.
        $this->out($this->asset('Laptop'));
        $this->copyTransactions(ReportDataResolver::MAX_ROWS);
        $this->assertErrorCode($this->postJson("/api/report-templates/{$template}/exports", ['format' => 'pdf']), 422, 'REPORT_TOO_LARGE');
        $this->assertSame(0, ReportExport::query()->count());
    }

    public function test_history_and_downloads_are_own_unless_history_view(): void
    {
        $template = $this->published('outgoing');
        $mine = $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'pdf'])->assertCreated()->json('data.id');

        $staff = $this->member(['report.view', 'report.generate', 'report.download']);
        $this->actingAs($staff, 'web');
        $this->getJson('/api/report-exports')->assertOk()->assertJsonCount(0, 'data');
        $this->assertErrorCode($this->getJson("/api/report-exports/{$mine}/download"), 404, 'NOT_FOUND');
        $own = $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'xlsx'])->assertCreated()->json('data.id');
        $this->getJson('/api/report-exports')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);

        $this->actingAs($this->member(['report.view', 'report.history.view']), 'web');
        $this->getJson('/api/report-exports')->assertOk()->assertJsonCount(2, 'data');
        $this->assertErrorCode($this->getJson("/api/report-exports/{$own}/download"), 403, 'FORBIDDEN');   // no report.download
        $this->assertErrorCode($this->postJson("/api/report-templates/{$template}/exports", ['format' => 'pdf']), 403, 'FORBIDDEN');

        // Another organization sees nothing of these.
        ['admin' => $other] = $this->createOrganization('BETA');
        $this->actingAs($other, 'web');
        $this->getJson('/api/report-exports')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(404, $this->getJson("/api/report-exports/{$mine}/download")->status());
    }

    public function test_preview_and_export_follow_the_data_scope_of_who_runs_them(): void
    {
        $inside = $this->asset('Laptop');
        $this->out($inside);
        $other = $this->otherLocationAsset('Kamera');
        $this->out($other);
        $template = $this->published('outgoing');
        $this->assertSame(2, $this->postJson("/api/report-templates/{$template}/run-preview")->json('data.total'));

        $scoped = $this->member(['report.view', 'report.generate', 'report.download'], location: $this->masters['location']->id);
        $this->actingAs($scoped, 'web');
        $this->assertSame(1, $this->postJson("/api/report-templates/{$template}/run-preview")->json('data.total'));
        $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'xlsx'])->assertCreated()->assertJsonPath('data.row_count', 1);
    }

    public function test_expired_files_are_removed_and_no_longer_download(): void
    {
        $template = $this->published('outgoing');
        $export = $this->postJson("/api/report-templates/{$template}/exports", ['format' => 'pdf'])->assertCreated()->json('data.id');
        $path = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => ReportExport::query()->findOrFail($export)->files()->value('path'));
        Storage::disk('local')->assertExists($path);

        $this->travel(ReportExportService::FILE_DAYS + 1)->days();
        $this->getJson('/api/report-exports')->assertJsonPath('data.0.status', 'expired')->assertJsonPath('data.0.downloadable', false);
        $this->assertErrorCode($this->getJson("/api/report-exports/{$export}/download"), 410, 'EXPORT_EXPIRED');

        $this->assertSame(0, Artisan::call('reports:expire'));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('expired', $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => ReportExport::query()->findOrFail($export)->status));
    }

    /** A sample copied, then published so it can be run. */
    private function published(string $sample): string
    {
        $id = $this->postJson('/api/report-templates/from-sample', ['sample' => $sample])->assertCreated()->json('data.id');
        $this->postJson("/api/report-templates/{$id}/publish")->assertOk();

        return $id;
    }

    private function asset(string $name, ?string $location = null): string
    {
        return $this->postJson('/api/assets', [
            'name' => $name, 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $location ?? $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    private function otherLocationAsset(string $name): string
    {
        $location = $this->postJson('/api/locations', ['name' => 'Gudang Lain '.Str::random(4)])->assertCreated()->json('data.id');

        return $this->asset($name, $location);
    }

    /** @param array<string, mixed> $data */
    private function out(string $assetId, array $data = []): void
    {
        $this->postJson('/api/transactions', [
            'asset_id' => $assetId, 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis', 'due_date' => now()->addWeek()->toDateString(), ...$data,
        ])->assertCreated();
    }

    /** @param list<string> $permissions */
    private function member(array $permissions, ?string $location = null): User
    {
        static $n = 0;
        $role = $this->createRole($this->org, 'RPT_ROLE_'.(++$n), ['organization.view', ...$permissions])->code;
        $scopes = $location === null ? [['scope_type' => 'organization']] : [['scope_type' => 'location', 'ref_id' => $location]];

        return $this->createTenantUser($this->org, [$role], $scopes);
    }

    /** Copies of the organization's one outgoing transaction, written straight to the table. */
    private function copyTransactions(int $copies): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () use ($copies) {
            $row = (array) DB::table('asset_transactions')->where('type', 'outgoing')->sole();
            unset($row['seq']);
            DB::table('asset_transactions')->insert(array_map(fn (int $i) => [
                ...$row, 'id' => (string) Str::uuid(), 'transaction_number' => $row['transaction_number'].'-C'.$i,
            ], range(1, $copies)));
        });
    }
}
