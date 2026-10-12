<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Services\TenantDefinitionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantDefinitionResource;
use App\Http\Resources\TenantVersionResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Tenant asset definitions (adoptions of generic definitions) and creation of new versions. */
class TenantDefinitionController extends Controller
{
    private const LIST_RELATIONS = ['genericDefinition', 'category', 'currentVersion', 'draftVersion'];

    public function __construct(private readonly TenantDefinitionService $definitions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'tenant_category_id' => ['nullable', 'uuid'],
            'generic_definition_id' => ['nullable', 'uuid'],
        ]);
        $query = TenantAssetDefinition::query()->with(self::LIST_RELATIONS)
            ->when($request->query('tenant_category_id'), fn ($q, $id) => $q->where('tenant_category_id', $id))
            ->when($request->query('generic_definition_id'), fn ($q, $id) => $q->where('generic_definition_id', $id));

        return TenantDefinitionResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    /** Adopt a generic definition. */
    public function store(Request $request, Tenancy $tenancy): TenantDefinitionResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'generic_definition_id' => ['required', 'uuid'],
            'generic_version_id' => ['nullable', 'uuid'],
            'code' => [...MasterRequests::codeRules(40), Rule::unique('tenant_asset_definitions', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tenant_category_id' => ['nullable', 'uuid'],
        ]);

        return new TenantDefinitionResource($this->definitions->adopt($data)->load(self::LIST_RELATIONS));
    }

    public function show(TenantAssetDefinition $tenantDefinition): TenantDefinitionResource
    {
        return new TenantDefinitionResource($tenantDefinition->load([
            ...self::LIST_RELATIONS,
            'versions' => fn ($q) => $q->with('genericVersion')->orderByDesc('version_number'),
        ]));
    }

    public function update(Request $request, TenantAssetDefinition $tenantDefinition): TenantDefinitionResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tenant_category_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new TenantDefinitionResource($this->definitions->update($tenantDefinition, $data)->load(self::LIST_RELATIONS));
    }

    public function archive(Request $request, TenantAssetDefinition $tenantDefinition): TenantDefinitionResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new TenantDefinitionResource($this->definitions->archive($tenantDefinition, $data['reason'] ?? null)->load(self::LIST_RELATIONS));
    }

    public function restore(TenantAssetDefinition $tenantDefinition): TenantDefinitionResource
    {
        return new TenantDefinitionResource($this->definitions->restore($tenantDefinition)->load(self::LIST_RELATIONS));
    }

    /** New draft version; optionally based on another released generic version (explicit upgrade). */
    public function createVersion(Request $request, TenantAssetDefinition $tenantDefinition): TenantVersionResource
    {
        $data = $request->validate(['generic_version_id' => ['nullable', 'uuid']]);
        $version = $this->definitions->createDraft($tenantDefinition, $data['generic_version_id'] ?? null);

        return new TenantVersionResource($version->load(['definition', 'genericVersion', 'tenantAttributes.unit', 'overrides']));
    }
}
