<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Services\DashboardAccessService;
use App\Domain\Dashboards\Services\DashboardService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Http\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard Builder: dashboards as containers (navigation, Overview, category dashboards,
 * custom dashboards, settings, explicit template updates). Widgets and their data have their
 * own endpoints; a dashboard the member may not open answers 404, as if it did not exist.
 */
class DashboardController extends Controller
{
    private const DETAIL = ['widgets.catalog', 'category', 'template', 'creator'];

    public function __construct(
        private readonly DashboardService $service,
        private readonly DashboardAccessService $access,
    ) {}

    /** Accessible dashboards (no widgets), for pickers and management lists. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'dashboard_type' => ['nullable', Rule::in(DashboardType::ALL)],
            'visibility' => ['nullable', Rule::in(DashboardType::VISIBILITIES)],
            'status' => ['nullable', Rule::in([Dashboard::STATUS_ACTIVE, Dashboard::STATUS_ARCHIVED])],
        ]);
        $this->service->overview();

        $query = $this->accessible()->withCount('widgets')->with(['category', 'creator'])
            ->where('status', $filters['status'] ?? Dashboard::STATUS_ACTIVE)
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('name', 'ilike', ListQuery::like($term)))
            ->when($filters['dashboard_type'] ?? null, fn ($q, $type) => $q->where('dashboard_type', $type))
            ->when($filters['visibility'] ?? null, fn ($q, $visibility) => $q->where('visibility', $visibility));
        $query = ListQuery::sort($query, $request, ['name' => 'name', 'dashboard_type' => 'dashboard_type', 'updated_at' => 'updated_at'], 'name');

        return DashboardResource::collection($query->paginate(ListQuery::perPage($request, 50))->withQueryString());
    }

    /**
     * Sidebar groups: Overview, every active category (with its dashboard once created),
     * My Dashboards (created by the member, including their asset board) and Shared With Me
     * (organization dashboards others made).
     */
    public function navigation(Request $request): JsonResponse
    {
        $overview = $this->service->overview();
        $userId = $request->user()->id;

        $custom = $this->accessible()->whereIn('dashboard_type', [DashboardType::CUSTOM, DashboardType::ASSET_BOARD])
            ->where('status', Dashboard::STATUS_ACTIVE)->with('creator')->withCount('widgets')->orderBy('name')->get();
        $categoryDashboards = Dashboard::query()->where('dashboard_type', DashboardType::CATEGORY)->pluck('id', 'category_id');
        $categories = TenantAssetCategory::query()->where('status', MasterStatus::ACTIVE)
            ->orderBy('name')->get(['id', 'parent_id', 'code', 'name'])
            ->map(fn (TenantAssetCategory $c) => [
                'category_id' => $c->id,
                'parent_id' => $c->parent_id,
                'code' => $c->code,
                'name' => $c->name,
                'dashboard_id' => $categoryDashboards[$c->id] ?? null,
            ])->values();
        $summary = fn ($dashboards) => DashboardResource::collection($dashboards->values())->resolve($request);

        return response()->json(['data' => [
            'default_dashboard_id' => $this->service->defaultFor($request->user())->id,
            'overview' => (new DashboardResource($overview))->resolve($request),
            'categories' => $categories,
            'mine' => $summary($custom->where('created_by', $userId)),
            'shared' => $summary($custom->where('created_by', '!==', $userId)),
        ]]);
    }

    /** The dashboard the member lands on (preference, organization default, else Overview). */
    public function default(Request $request): DashboardResource
    {
        return $this->detail($this->service->defaultFor($request->user()));
    }

    public function overview(): DashboardResource
    {
        return $this->detail($this->service->overview());
    }

    /** A category's dashboard, created from the matching template the first time it is opened. */
    public function category(TenantAssetCategory $tenantCategory): DashboardResource
    {
        return $this->detail($this->service->forCategory($tenantCategory));
    }

    /** The member's asset board: provisioned from their role's template on first open (F-06 §4.1). */
    public function assetBoard(Request $request): DashboardResource
    {
        return $this->detail($this->service->assetBoard($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(DashboardService::createRules());
        Gate::authorize('create', [Dashboard::class, $data['visibility']]);

        return $this->detail($this->service->createCustom($data, $request->user()), created: true)->response();
    }

    public function show(Dashboard $dashboard): DashboardResource
    {
        return $this->detail($dashboard);
    }

    public function update(Request $request, Dashboard $dashboard): DashboardResource
    {
        $this->visible($dashboard);
        Gate::authorize('manage', $dashboard);
        $data = $request->validate(DashboardService::updateRules());

        return $this->detail($this->service->update($dashboard, $data));
    }

    public function destroy(Dashboard $dashboard): Response
    {
        $this->visible($dashboard);
        Gate::authorize('delete', $dashboard);
        $this->service->delete($dashboard);

        return response()->noContent();
    }

    /** Explicit template update: the widgets are replaced by a fresh copy of the template. */
    public function applyTemplate(Request $request, Dashboard $dashboard): DashboardResource
    {
        $this->visible($dashboard);
        Gate::authorize('manage', $dashboard);
        $data = $request->validate(['template_id' => ['nullable', 'uuid']]);

        return $this->detail($this->service->applyTemplate($dashboard, $data['template_id'] ?? null, $request->user()));
    }

    private function detail(Dashboard $dashboard, bool $created = false): DashboardResource
    {
        // Overview and category dashboards may be created by a read; only POST answers 201.
        $dashboard->wasRecentlyCreated = $created;

        return new DashboardResource($this->visible($dashboard)->load(self::DETAIL));
    }

    /** Dashboards the member may not open do not exist for them. */
    private function visible(Dashboard $dashboard): Dashboard
    {
        if (Gate::denies('view', $dashboard)) {
            throw (new ModelNotFoundException)->setModel(Dashboard::class);
        }

        return $dashboard;
    }

    /** @return Builder<Dashboard> */
    private function accessible(): Builder
    {
        $query = Dashboard::query()->with(['shares' => fn ($q) => $this->access->constrainShares($q)]);
        $this->access->scopeAccessible($query);

        return $query;
    }
}
