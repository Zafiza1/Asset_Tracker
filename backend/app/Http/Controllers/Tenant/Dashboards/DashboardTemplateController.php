<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Services\DashboardTemplateService;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard templates: the system ones (read-only, copyable) and the organization's own.
 * Reading needs dashboard.view; writing needs dashboard.manage (route middleware).
 */
class DashboardTemplateController extends Controller
{
    public function __construct(
        private readonly DashboardTemplateService $service,
        private readonly Tenancy $tenancy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'dashboard_type' => ['nullable', Rule::in(DashboardType::ALL)],
            'scope' => ['nullable', Rule::in([DashboardTemplate::SCOPE_SYSTEM, DashboardTemplate::SCOPE_ORGANIZATION])],
            'category_type_key' => ['nullable', 'string', 'max:60'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);
        // Inactive templates only matter to the people who manage them.
        $inactive = ($filters['include_inactive'] ?? false) && $this->tenancy->tenant()->can('dashboard.manage');

        $templates = DashboardTemplate::query()->withCount('widgets')
            ->when(! $inactive, fn ($q) => $q->where('is_active', true))
            ->when($filters['dashboard_type'] ?? null, fn ($q, $type) => $q->where('dashboard_type', $type))
            ->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('scope', $scope))
            ->when($filters['category_type_key'] ?? null, fn ($q, $key) => $q->where('category_type_key', $key))
            ->orderByRaw("CASE scope WHEN 'organization' THEN 0 ELSE 1 END")
            ->orderBy('dashboard_type')->orderBy('name')
            ->get();

        return DashboardTemplateResource::collection($templates);
    }

    public function show(DashboardTemplate $dashboardTemplate): DashboardTemplateResource
    {
        return new DashboardTemplateResource($dashboardTemplate->load(['widgets.catalog', 'creator']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(DashboardTemplateService::createRules());

        return (new DashboardTemplateResource($this->service->create($data, $request->user())->load(['widgets.catalog', 'creator'])))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, DashboardTemplate $dashboardTemplate): DashboardTemplateResource
    {
        $data = $request->validate(DashboardTemplateService::updateRules());

        return new DashboardTemplateResource($this->service->update($dashboardTemplate, $data)->load(['widgets.catalog', 'creator']));
    }

    public function destroy(DashboardTemplate $dashboardTemplate): Response
    {
        $this->service->delete($dashboardTemplate);

        return response()->noContent();
    }
}
