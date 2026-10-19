<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    private const RELATIONS = ['parent', 'locationType'];

    public function __construct(private readonly OrganizationStructureService $structure) {}

    /** Default sort is the tree order (materialized path): parents directly before their children. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'parent_id' => ['nullable', 'uuid'],
            'location_type_id' => ['nullable', 'uuid'],
        ]);
        $query = Location::query()->with(self::RELATIONS)
            ->when($request->query('parent_id'), fn ($q, $id) => $q->where('parent_id', $id))
            ->when($request->query('location_type_id'), fn ($q, $id) => $q->where('location_type_id', $id));

        return LocationResource::collection(MasterRequests::list($query, $request,
            ['tree' => 'path', 'code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'tree'));
    }

    public function store(Request $request, Tenancy $tenancy): LocationResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('locations', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'location_type_id' => ['required', 'uuid'],
        ]);

        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'locations', $tenancy->organizationId());

        return new LocationResource($this->structure->createLocation($data)->load(self::RELATIONS));
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load(self::RELATIONS));
    }

    public function update(Request $request, Location $location): LocationResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'location_type_id' => ['sometimes', 'required', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new LocationResource($this->structure->updateLocation($location, $data)->load(self::RELATIONS));
    }

    public function archive(Request $request, Location $location): LocationResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new LocationResource($this->structure->archiveLocation($location, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(Location $location): LocationResource
    {
        return new LocationResource($this->structure->restoreLocation($location)->load(self::RELATIONS));
    }
}
