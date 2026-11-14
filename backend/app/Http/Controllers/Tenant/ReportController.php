<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\AssetReport;
use App\Domain\Reports\AssetReportExport;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Asset Reports container: the filtered asset register with totals, on screen or as PDF/Excel. */
class ReportController extends Controller
{
    public function __construct(
        private readonly AssetReport $report,
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    public function assets(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);
        $query = ListQuery::sort($this->report->query($filters), $request, AssetReport::sorts(), AssetReport::DEFAULT_SORT);

        return AssetResource::collection($query->paginate(ListQuery::perPage($request, 25))->withQueryString())
            ->additional(['summary' => $this->report->summary($filters)]);
    }

    public function exportAssets(Request $request): Response
    {
        $request->validate(['format' => ['required', Rule::in(array_keys(AssetReportExport::LIMITS))]]);
        $format = $request->query('format');
        $filters = $this->filters($request);

        $summary = $this->report->summary($filters);
        $limit = AssetReportExport::LIMITS[$format];
        if ($summary['total'] > $limit) {
            throw ApiException::unprocessable('REPORT_TOO_LARGE', sprintf(
                'Laporan berisi %s aset, melebihi batas %s aset untuk format %s. Persempit filter laporan%s.',
                number_format($summary['total'], 0, ',', '.'), number_format($limit, 0, ',', '.'), strtoupper($format),
                $format === 'pdf' ? ' atau gunakan format Excel' : '',
            ));
        }

        $organization = $this->tenancy->tenant()->organization;
        $query = ListQuery::sort($this->report->query($filters), $request, AssetReport::sorts(), AssetReport::DEFAULT_SORT);
        $export = new AssetReportExport($organization, $query, $this->report->describe($filters), $summary);
        $filename = 'laporan_aset_'.now($organization->timezone ?: 'UTC')->format('Ymd_His').'.'.$format;

        $this->audit->record('report.exported', 'Report', null, metadata: [
            'report' => 'assets', 'format' => $format, 'rows' => $summary['total'], 'filters' => array_filter($filters),
        ]);

        if ($format === 'pdf') {
            return response($export->pdf(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response()->download($export->xlsx(), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $filters = $request->validate(AssetReport::rules());
        $categoryIds = array_values(array_filter(explode(',', (string) ($filters['category_ids'] ?? ''))));
        $request->merge(['category_id_list' => $categoryIds])->validate(['category_id_list.*' => ['uuid']]);

        return $filters;
    }
}
