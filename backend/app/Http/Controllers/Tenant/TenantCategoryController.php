<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantCategoryResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TenantCategoryController extends Controller
{
    private const RELATIONS = ['parent', 'genericCategory'];

    public function __construct(private readonly TenantCategoryService $categories) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['generic_category_id' => ['nullable', 'uuid']]);
        $query = TenantAssetCategory::query()->with(self::RELATIONS)
            ->when($request->query('generic_category_id'), fn ($q, $id) => $q->where('generic_category_id', $id));

        return TenantCategoryResource::collection(MasterRequests::list($query, $request,
            ['tree' => 'path', 'code' => 'code', 'name' => 'name', 'status' => 'status'], 'tree'));
    }

    public function store(Request $request, Tenancy $tenancy): TenantCategoryResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(), Rule::unique('tenant_asset_categories', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'generic_category_id' => ['nullable', 'uuid'],
        ]);

        return new TenantCategoryResource($this->categories->create($data)->load(self::RELATIONS));
    }

    public function show(TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        return new TenantCategoryResource($tenantCategory->load(self::RELATIONS));
    }

    public function update(Request $request, TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new TenantCategoryResource($this->categories->update($tenantCategory, $data)->load(self::RELATIONS));
    }

    public function archive(Request $request, TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new TenantCategoryResource($this->categories->archive($tenantCategory, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        return new TenantCategoryResource($this->categories->restore($tenantCategory)->load(self::RELATIONS));
    }
}
