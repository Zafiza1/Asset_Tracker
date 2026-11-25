<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(MasterRequests::list(Customer::query(), $request,
            ['code' => 'code', 'name' => 'name', 'address' => 'address', 'status' => 'status', 'created_at' => 'created_at'],
            'name', ['code', 'name', 'address', 'contact_name']));
    }

    public function store(Request $request, Tenancy $tenancy): CustomerResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('customers', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(creating: true),
        ]);
        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'customers', $tenancy->organizationId());

        return new CustomerResource($this->masters->createCustomer($data));
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([...$this->rules(creating: false), 'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)]]);

        return new CustomerResource($this->masters->updateCustomer($customer, $data));
    }

    public function archive(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new CustomerResource($this->masters->archiveCustomer($customer, $data['reason'] ?? null));
    }

    public function restore(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->masters->restoreCustomer($customer));
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...($creating ? ['required'] : ['sometimes', 'required']), 'string', 'max:150'],
            'address' => [...$optional, 'string', 'max:500'],
            'contact_name' => [...$optional, 'string', 'max:150'],
            'phone' => [...$optional, 'string', 'max:30'],
        ];
    }
}
