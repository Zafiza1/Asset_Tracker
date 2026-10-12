<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\AssetCondition;
use App\Domain\TenantMaster\Services\ConditionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenceResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ConditionController extends Controller
{
    public function __construct(private readonly ConditionService $conditions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(AssetCondition::query(), $request,
            ['sort_order' => 'sort_order', 'severity' => 'severity', 'code' => 'code', 'name' => 'name'], 'sort_order'));
    }

    public function store(Request $request, Tenancy $tenancy): ReferenceResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(), Rule::unique('asset_conditions', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(),
        ]);

        return new ReferenceResource($this->conditions->create($data));
    }

    public function show(AssetCondition $condition): ReferenceResource
    {
        return new ReferenceResource($condition);
    }

    public function update(Request $request, AssetCondition $condition): ReferenceResource
    {
        $data = $request->validate([
            ...array_map(fn ($rules) => ['sometimes', ...$rules], $this->rules()),
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new ReferenceResource($this->conditions->update($condition, $data));
    }

    public function archive(Request $request, AssetCondition $condition): ReferenceResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new ReferenceResource($this->conditions->archive($condition, $data['reason'] ?? null));
    }

    public function restore(AssetCondition $condition): ReferenceResource
    {
        return new ReferenceResource($this->conditions->restore($condition));
    }

    /** Copies standard conditions the organization does not have yet (idempotent). */
    public function importGeneric(Tenancy $tenancy): JsonResponse
    {
        $created = $this->conditions->importGeneric($tenancy->organizationId());

        return response()->json(['data' => ReferenceResource::collection($created), 'meta' => ['created' => count($created)]]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'severity' => ['required', 'integer', 'min:1', 'max:9'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
