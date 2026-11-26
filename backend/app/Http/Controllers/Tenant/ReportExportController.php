<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\Models\ReportExport;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Services\ReportDataResolver;
use App\Domain\ReportDesigner\Services\ReportExportService;
use App\Domain\ReportDesigner\Services\ReportPreviewResolver;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportExportResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Running a published template (docs/factory/features/F-05): its filters, an on-screen preview
 * of the active version, PDF/Excel generation, and the export history with downloads.
 */
class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $exports,
        private readonly ReportDataResolver $data,
        private readonly ReportPreviewResolver $preview,
    ) {}

    /** Filters the run page shows for the template's source, with the template's defaults. */
    public function filters(ReportTemplate $reportTemplate): JsonResponse
    {
        $version = $this->publishedVersion($reportTemplate);

        return response()->json(['data' => [
            'data_source' => $reportTemplate->data_source,
            'defaults' => array_intersect_key(
                is_array($version->design['filters'] ?? null) ? $version->design['filters'] : [],
                ReportDataResolver::filterRules($reportTemplate->data_source),
            ),
            'fields' => self::filterFields($reportTemplate->data_source),
            'max_rows' => ReportDataResolver::MAX_ROWS,
        ]]);
    }

    /** The active version with live data, as the run page shows it before downloading. */
    public function runPreview(Request $request, ReportTemplate $reportTemplate): JsonResponse
    {
        $version = $this->publishedVersion($reportTemplate);
        $filters = $this->data->filtersFor($reportTemplate->data_source, $version->design ?? [], $this->input($request, $reportTemplate));

        return response()->json(['data' => [
            ...$this->preview->preview($reportTemplate, $version, $filters),
            'filters' => $filters,
            'filter_description' => $this->exports->describe($reportTemplate->data_source, $filters),
            'max_rows' => ReportDataResolver::MAX_ROWS,
        ]]);
    }

    public function store(Request $request, ReportTemplate $reportTemplate): JsonResponse
    {
        $request->validate(['format' => ['required', Rule::in(ReportExport::FORMATS)]]);
        $export = $this->exports->generate($reportTemplate, $request->input('format'), $this->input($request, $reportTemplate), $this->user($request));

        return (new ReportExportResource($export))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['template_id' => ['nullable', 'uuid']]);
        $query = $this->exports->visible($this->user($request))->with(['template', 'version', 'creator'])
            ->when($request->query('template_id'), fn ($q, $id) => $q->where('template_id', $id))
            ->orderByDesc('created_at');

        return ReportExportResource::collection($query->paginate(ListQuery::perPage($request, 10))->withQueryString());
    }

    public function download(Request $request, ReportExport $reportExport): StreamedResponse
    {
        // Someone else's export without report.history.view: the same answer as a missing one.
        if (! $this->exports->visible($this->user($request))->whereKey($reportExport->id)->exists()) {
            throw new ApiException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $this->exports->download($reportExport);
    }

    private function publishedVersion(ReportTemplate $template)
    {
        if (! $template->canGenerate()) {
            throw ApiException::conflict('TEMPLATE_NOT_PUBLISHED', 'Terbitkan template terlebih dahulu sebelum menjalankan laporan.');
        }

        return $template->activeVersion()->firstOrFail();
    }

    /** @return array<string, mixed> the `filters` object, each key checked by the source's rules */
    private function input(Request $request, ReportTemplate $template): array
    {
        $rules = ['filters' => ['nullable', 'array']];
        foreach (ReportDataResolver::filterRules($template->data_source) as $key => $rule) {
            $rules["filters.{$key}"] = $rule;
        }

        return $request->validate($rules)['filters'] ?? [];
    }

    /**
     * Filter inputs per source, in display order. `options` for fixed lists; `lookup` names
     * the master list the page loads itself (locations, categories).
     *
     * @return list<array<string, mixed>>
     */
    private static function filterFields(string $source): array
    {
        $options = fn (array $labels) => array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
        $reasons = [];
        foreach (OutgoingReason::REASONS as $nature => $list) {
            foreach ($list as $code => $label) {
                // "Lainnya" exists for both natures: list it once.
                $reasons[$code] ??= ['value' => $code, 'label' => $label, 'group' => $code === OutgoingReason::OTHER ? null : OutgoingReason::NATURE_LABELS[$nature]];
            }
        }

        return match ($source) {
            'asset_transaction' => [
                ['key' => 'date_from', 'label' => 'Dari tanggal', 'type' => 'date', 'range' => 'date'],
                ['key' => 'date_to', 'label' => 'Sampai', 'type' => 'date', 'range' => 'date'],
                ['key' => 'type', 'label' => 'Jenis transaksi', 'type' => 'select', 'options' => $options(TransactionType::LABELS)],
                ['key' => 'outgoing_nature', 'label' => 'Sifat keluar', 'type' => 'select', 'options' => $options(OutgoingReason::NATURE_LABELS)],
                ['key' => 'reason_code', 'label' => 'Alasan', 'type' => 'select', 'options' => array_values($reasons)],
                ['key' => 'not_returned', 'label' => 'Hanya yang belum kembali', 'type' => 'boolean'],
                ['key' => 'search', 'label' => 'Cari', 'type' => 'text', 'placeholder' => 'Nomor, aset, pihak luar, dokumen'],
            ],
            'asset' => [
                ['key' => 'search', 'label' => 'Cari', 'type' => 'text', 'placeholder' => 'Nama, no seri, deskripsi'],
                ['key' => 'category_ids', 'label' => 'Kategori', 'type' => 'lookup', 'lookup' => 'categories'],
                ['key' => 'location_id', 'label' => 'Lokasi', 'type' => 'lookup', 'lookup' => 'locations'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $options(AssetStatus::labels())],
                ['key' => 'archived', 'label' => 'Aset nonaktif', 'type' => 'select', 'options' => [['value' => 'only', 'label' => 'Hanya nonaktif'], ['value' => 'all', 'label' => 'Aktif & nonaktif']]],
                ['key' => 'acquired_from', 'label' => 'Perolehan dari', 'type' => 'date', 'range' => 'acquired'],
                ['key' => 'acquired_to', 'label' => 'Perolehan sampai', 'type' => 'date', 'range' => 'acquired'],
            ],
            default => [],
        };
    }

    private function user(Request $request): User
    {
        return $request->user();
    }
}
