<?php

namespace App\Domain\ReportDesigner\Services;

use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\ReportDesigner\ExportStatus;
use App\Domain\ReportDesigner\Models\ReportExport;
use App\Domain\ReportDesigner\Models\ReportExportFile;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Rendering\ReportExcelRenderer;
use App\Domain\ReportDesigner\Rendering\ReportPdfRenderer;
use App\Domain\Reports\AssetReport;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Numbering\NumberSequence;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs a published template into a PDF or Excel file and keeps it in the export history
 * (docs/factory/features/F-05). Generation is synchronous and capped at
 * ReportDataResolver::MAX_ROWS rows (D-13); files are kept FILE_DAYS days (D-14).
 */
final class ReportExportService
{
    public const FILE_DAYS = 30;

    public const DISK = 'local';

    public const NUMBER_FORMAT = '{TYPE}-{YYYY}-{SEQ:6}';

    private const MIME = [
        ReportExport::FORMAT_PDF => 'application/pdf',
        ReportExport::FORMAT_XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly ReportDataResolver $data,
        private readonly ReportPreviewResolver $preview,
        private readonly ReportPdfRenderer $pdf,
        private readonly ReportExcelRenderer $excel,
        private readonly NumberSequence $numbers,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
        private readonly AssetReport $assetReport,
    ) {}

    /** @param array<string, mixed> $input filters sent by the user (over the template's defaults) */
    public function generate(ReportTemplate $template, string $format, array $input, User $user): ReportExport
    {
        if (! $template->canGenerate()) {
            throw ApiException::conflict('TEMPLATE_NOT_PUBLISHED', 'Terbitkan template terlebih dahulu sebelum menjalankan laporan.');
        }
        $version = $template->activeVersion()->firstOrFail();
        $design = is_array($version->design) ? $version->design : [];
        $filters = $this->data->filtersFor($template->data_source, $design, $input);

        $result = $this->preview->preview($template, $version, $filters, ReportDataResolver::MAX_ROWS);
        if (! $result['valid']) {
            throw ApiException::unprocessable('TEMPLATE_INVALID', 'Desain template memiliki kesalahan. Perbaiki di desainer lalu terbitkan ulang.', ['issues' => $result['issues']]);
        }
        if ($result['total'] > ReportDataResolver::MAX_ROWS) {
            throw ApiException::unprocessable('REPORT_TOO_LARGE', sprintf(
                'Laporan berisi %s baris, melebihi batas %s baris. Persempit tanggal atau filter lain.',
                number_format($result['total'], 0, ',', '.'), number_format(ReportDataResolver::MAX_ROWS, 0, ',', '.'),
            ), ['total' => $result['total'], 'limit' => ReportDataResolver::MAX_ROWS]);
        }

        $organization = $this->tenancy->tenant()->organization;
        $describe = $this->describe($template->data_source, $filters);
        $printed = now($organization->timezone ?: 'UTC')->format('d/m/Y H.i');
        $temp = $format === ReportExport::FORMAT_PDF
            ? $this->writeTemp($this->pdf->render($design['page'] ?? [], $result['elements'], $template->name, $user->name))
            : $this->excel->render($template->name, [
                $organization->name,
                'Filter: '.($describe === [] ? 'tidak ada' : implode('; ', $describe)),
                "Dibuat {$printed} oleh {$user->name}",
            ], ...$this->excelData($template->data_source, $result));

        try {
            $export = DB::transaction(function () use ($template, $version, $format, $filters, $describe, $result, $user, $temp, $organization) {
                $number = $this->numbers->next('report_export', self::NUMBER_FORMAT, 'RPT');
                $export = new ReportExport([
                    'template_id' => $template->id,
                    'template_version_id' => $version->id,
                    'export_number' => $number,
                    'format' => $format,
                    'status' => ExportStatus::COMPLETED,
                    // The filters as applied, plus their description for the history list.
                    'filters' => ['values' => array_filter($filters, fn ($v) => $v !== null && $v !== ''), 'description' => $describe],
                    'row_count' => min($result['total'], ReportDataResolver::MAX_ROWS),
                    'file_size' => filesize($temp),
                    'created_by' => $user->id,
                    'completed_at' => now(),
                    'expires_at' => now()->addDays(self::FILE_DAYS),
                ]);
                $export->save();

                // Unguessable path on the private disk; only the download endpoint serves it.
                $path = "report-exports/{$organization->id}/".Str::uuid7()."-{$export->id}.{$format}";
                Storage::disk(self::DISK)->put($path, (string) file_get_contents($temp));
                (new ReportExportFile([
                    'export_id' => $export->id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => Str::slug($template->name, '_')."_{$number}.{$format}",
                    'mime' => self::MIME[$format],
                    'size' => filesize($temp),
                ]))->save();

                $this->audit->record('report.exported', $export, metadata: [
                    'template' => $template->code, 'version' => $version->version_number, 'format' => $format,
                    'rows' => $export->row_count, 'filters' => $export->filters['values'],
                ]);

                return $export;
            });
        } finally {
            @unlink($temp);
        }

        return $export->load(['template', 'version', 'creator', 'files']);
    }

    /** Exports the user may see: the whole organization with report.history.view, else their own (D-17). */
    public function visible(User $user): Builder
    {
        $query = ReportExport::query();

        return $this->tenancy->tenant()->can('report.history.view') ? $query : $query->where('created_by', $user->id);
    }

    public function download(ReportExport $export): StreamedResponse
    {
        $file = $export->files()->first();
        $disk = Storage::disk($file?->disk ?? self::DISK);
        if ($export->status === ExportStatus::EXPIRED || ($export->expires_at?->isPast() ?? false) || $file === null || ! $disk->exists($file->path)) {
            throw new ApiException('EXPORT_EXPIRED', 'File laporan sudah kedaluwarsa. Jalankan ulang laporannya.', 410);
        }
        $this->audit->record('report.downloaded', $export, metadata: ['export_number' => $export->export_number]);

        return $disk->download($file->path, $file->original_name, ['Content-Type' => $file->mime]);
    }

    /** Marks exports past their expiry as expired and deletes their files (every organization). */
    public function expireFiles(): int
    {
        return $this->tenancy->runAsSystem(function () {
            $count = 0;
            ReportExport::query()->where('status', ExportStatus::COMPLETED)->where('expires_at', '<=', now())
                ->with('files')->chunkById(200, function ($exports) use (&$count) {
                    foreach ($exports as $export) {
                        foreach ($export->files as $file) {
                            Storage::disk($file->disk)->delete($file->path);
                        }
                        $export->forceFill(['status' => ExportStatus::EXPIRED])->save();
                        $count++;
                    }
                });

            return $count;
        });
    }

    /**
     * Columns and rows of the Excel sheet: the first visible table of the design, or every field
     * the design binds when it has no table.
     *
     * @return array{0: list<array{key: string, label: string}>, 1: list<array<string, string>>}
     */
    private function excelData(string $source, array $result): array
    {
        foreach ($result['elements'] as $element) {
            if (in_array($element['type'] ?? '', ['table', 'repeating_section'], true) && ($element['visible'] ?? true) && ($element['columns'] ?? []) !== []) {
                return [array_map(fn ($c) => ['key' => (string) $c['key'], 'label' => (string) $c['label']], $element['columns']), $element['rows'] ?? []];
            }
        }
        $keys = array_keys($result['rows'][0] ?? []);
        $fields = FieldCatalog::fields($source);

        return [array_map(fn ($k) => ['key' => $k, 'label' => (string) ($fields[$k][0] ?? $k)], $keys), $result['rows']];
    }

    /** Human-readable applied filters, for the file heading and the history list. @return list<string> */
    public function describe(string $source, array $filters): array
    {
        if ($source === 'asset') {
            return $this->assetReport->describe($filters);
        }
        $lines = [];
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if ($from || $to) {
            $lines[] = 'Tanggal: '.($from ? AssetReport::date($from) : '…').' s.d. '.($to ? AssetReport::date($to) : '…');
        }
        if ($filters['type'] ?? null) {
            $lines[] = 'Jenis: '.(TransactionType::LABELS[$filters['type']] ?? $filters['type']);
        }
        if ($filters['outgoing_nature'] ?? null) {
            $lines[] = 'Sifat: '.(OutgoingReason::NATURE_LABELS[$filters['outgoing_nature']] ?? $filters['outgoing_nature']);
        }
        if ($filters['reason_code'] ?? null) {
            $label = OutgoingReason::label(OutgoingReason::TEMPORARY, $filters['reason_code']) ?? OutgoingReason::label(OutgoingReason::PERMANENT, $filters['reason_code']);
            $lines[] = 'Alasan: '.($label ?? $filters['reason_code']);
        }
        if (! empty($filters['not_returned'])) {
            $lines[] = 'Hanya yang belum kembali';
        }
        if ($filters['search'] ?? null) {
            $lines[] = 'Pencarian: "'.$filters['search'].'"';
        }

        return $lines;
    }

    private function writeTemp(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rpt');
        file_put_contents($path, $bytes);

        return $path;
    }
}
