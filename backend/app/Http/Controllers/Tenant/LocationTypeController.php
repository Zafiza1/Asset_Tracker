<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationTypeResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Location types of the organization: the built-in ones (read-only) plus its own. */
class LocationTypeController extends Controller
{
    public function __construct(private readonly OrganizationStructureService $structure) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Own types first, then built-in ones, each by name.
        $query = LocationType::query()->orderByRaw('organization_id IS NULL');

        return LocationTypeResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    public function store(Request $request, Tenancy $tenancy): LocationTypeResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('location_types', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'location_types', $tenancy->organizationId());

        return new LocationTypeResource($this->structure->createLocationType($data));
    }

    public function show(LocationType $locationType): LocationTypeResource
    {
        return new LocationTypeResource($locationType);
    }

    public function update(Request $request, LocationType $locationType): LocationTypeResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new LocationTypeResource($this->structure->updateLocationType($locationType, $data));
    }

    public function archive(Request $request, LocationType $locationType): LocationTypeResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new LocationTypeResource($this->structure->archiveLocationType($locationType, $data['reason'] ?? null));
    }

    public function restore(LocationType $locationType): LocationTypeResource
    {
        return new LocationTypeResource($this->structure->restoreLocationType($locationType));
    }
}
