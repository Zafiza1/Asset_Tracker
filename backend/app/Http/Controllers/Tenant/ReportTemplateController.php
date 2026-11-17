<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Services\DesignValidator;
use App\Domain\ReportDesigner\Services\ReportDataResolver;
use App\Domain\ReportDesigner\Services\ReportPreviewResolver;
use App\Domain\ReportDesigner\Services\ReportTemplateService;
use App\Domain\ReportDesigner\TemplateStatus;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportTemplateResource;
use App\Http\Resources\ReportTemplateVersionResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Custom Report Designer: template management (list, CRUD, duplicate, publish, archive, versions). */
class ReportTemplateController extends Controller
{
    public function __construct(
        private readonly ReportTemplateService $service,
        private readonly DesignValidator $validator,
        private readonly ReportDataResolver $data,
        private readonly ReportPreviewResolver $preview,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(TemplateStatus::ALL)],
            'data_source' => ['nullable', Rule::in(['asset', 'asset_transaction'])],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $query = ReportTemplate::query()->withCount('versions')->with('creator')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'ilike', ListQuery::like($term))
                ->orWhere('name', 'ilike', ListQuery::like($term))
                ->orWhere('description', 'ilike', ListQuery::like($term))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['data_source'] ?? null, fn ($q, $source) => $q->where('data_source', $source))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category));

        $query = ListQuery::sort($query, $request, self::sorts(), 'name');

        return ReportTemplateResource::collection(
            $query->paginate(ListQuery::perPage($request, 15))->withQueryString());
    }

    public function store(Request $request): ReportTemplateResource|JsonResponse
    {
        $data = $request->validate(ReportTemplateService::rules(creating: true));

        return (new ReportTemplateResource($this->service->create($data, $this->user($request))))
            ->response()->setStatusCode(201);
    }

    public function show(ReportTemplate $reportTemplate): ReportTemplateResource
    {
        return new ReportTemplateResource($reportTemplate->load(['creator', 'versions.creator', 'versions.publisher']));
    }

    public function update(Request $request, ReportTemplate $reportTemplate): ReportTemplateResource
    {
        $data = $request->validate(ReportTemplateService::rules());

        return new ReportTemplateResource($this->service->update($reportTemplate, $data, $this->user($request))
            ->load(['creator', 'versions.creator', 'versions.publisher']));
    }

    public function destroy(ReportTemplate $reportTemplate): Response
    {
        $this->service->delete($reportTemplate);

        return response()->noContent();
    }

    public function duplicate(Request $request, ReportTemplate $reportTemplate): ReportTemplateResource|JsonResponse
    {
        return (new ReportTemplateResource($this->service->duplicate($reportTemplate, $this->user($request))))
            ->response()->setStatusCode(201);
    }

    public function publish(Request $request, ReportTemplate $reportTemplate): ReportTemplateResource
    {
        $data = $request->validate(['version_number' => ['nullable', 'integer', 'min:1']]);

        return new ReportTemplateResource($this->service->publish($reportTemplate, $data['version_number'] ?? null, $this->user($request))
            ->load(['creator', 'versions.creator', 'versions.publisher']));
    }

    public function archive(ReportTemplate $reportTemplate): ReportTemplateResource
    {
        return new ReportTemplateResource($this->service->archive($reportTemplate)->load('creator'));
    }

    /**
     * Static design check against the Field Registry: every {{binding}}, table column,
     * condition and geometry rule. Accepts an unsaved `design` or a stored version.
     */
    public function validateDesign(Request $request, ReportTemplate $reportTemplate): JsonResponse
    {
        $data = $request->validate([
            'design' => ['nullable', 'array'],
            'version_number' => ['nullable', 'integer', 'min:1'],
        ]);

        $design = $data['design'] ?? $this->preview->versionFor($reportTemplate, $data['version_number'] ?? null)?->design;
        $check = $this->validator->validate(is_array($design) ? $design : [], $reportTemplate->data_source);

        return response()->json(['data' => $check]);
    }

    /**
     * Resolve the design against live tenant data (bounded row set). Read-only: it
     * never writes exports, files or template state.
     */
    public function preview(Request $request, ReportTemplate $reportTemplate): JsonResponse
    {
        $rules = [
            'version_number' => ['nullable', 'integer', 'min:1'],
            'filters' => ['nullable', 'array'],
        ];
        foreach (ReportDataResolver::filterRules($reportTemplate->data_source) as $key => $rule) {
            $rules["filters.{$key}"] = $rule;
        }
        $data = $request->validate($rules);

        $version = $this->preview->versionFor($reportTemplate, $data['version_number'] ?? null);
        if ($version === null) {
            throw new ApiException('NOT_FOUND', 'Versi template tidak ditemukan.', 404);
        }

        $filters = $this->data->filters($reportTemplate->data_source, $data['filters'] ?? []);

        return response()->json(['data' => $this->preview->preview($reportTemplate, $version, $filters)]);
    }

    public function versions(ReportTemplate $reportTemplate): AnonymousResourceCollection
    {
        return ReportTemplateVersionResource::collection(
            $reportTemplate->versions()->with(['creator', 'publisher'])->paginate(ListQuery::perPage(request(), 20)));
    }

    /** @return array<string, string> */
    private static function sorts(): array
    {
        return [
            'code' => 'code', 'name' => 'name', 'data_source' => 'data_source',
            'status' => 'status', 'category' => 'category',
            'created_at' => 'created_at', 'updated_at' => 'updated_at',
        ];
    }

    private function user(Request $request): User
    {
        return $request->user();
    }
}
