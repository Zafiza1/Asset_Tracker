<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(private readonly OrganizationStructureService $structure) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Department::query();

        return DepartmentResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    public function store(Request $request, Tenancy $tenancy): DepartmentResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(), Rule::unique('departments', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
        ]);

        return new DepartmentResource($this->structure->createDepartment($data));
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new DepartmentResource($this->structure->updateDepartment($department, $data));
    }

    public function archive(Request $request, Department $department): DepartmentResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new DepartmentResource($this->structure->archiveDepartment($department, $data['reason'] ?? null));
    }

    public function restore(Department $department): DepartmentResource
    {
        return new DepartmentResource($this->structure->restoreDepartment($department));
    }
}
