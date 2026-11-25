<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['position' => ['nullable', Rule::in(array_keys(Employee::POSITIONS))]]);
        $query = Employee::query()->when($request->query('position'), fn ($q, $position) => $q->where('position', $position));

        return EmployeeResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'position' => 'position', 'status' => 'status', 'created_at' => 'created_at'],
            'name', ['code', 'name', 'phone']));
    }

    public function store(Request $request, Tenancy $tenancy): EmployeeResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('employees', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(creating: true),
        ]);
        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'employees', $tenancy->organizationId());

        return new EmployeeResource($this->masters->createEmployee($data));
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee);
    }

    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([...$this->rules(creating: false), 'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)]]);

        return new EmployeeResource($this->masters->updateEmployee($employee, $data));
    }

    public function archive(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new EmployeeResource($this->masters->archiveEmployee($employee, $data['reason'] ?? null));
    }

    public function restore(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->masters->restoreEmployee($employee));
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'position' => [...$required, Rule::in(array_keys(Employee::POSITIONS))],
            'phone' => [...$optional, 'string', 'max:30'],
            'license_number' => [...$optional, 'string', 'max:50'],
            'license_expires_on' => [...$optional, 'date_format:Y-m-d'],
        ];
    }
}
