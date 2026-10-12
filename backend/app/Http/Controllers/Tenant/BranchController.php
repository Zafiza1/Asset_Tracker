<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(private readonly OrganizationStructureService $structure) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BranchResource::collection(MasterRequests::list(Branch::query(), $request,
            ['code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    public function store(Request $request, Tenancy $tenancy): BranchResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(), Rule::unique('branches', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(),
        ]);

        return new BranchResource($this->structure->createBranch($data));
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(Request $request, Branch $branch): BranchResource
    {
        $data = $request->validate([
            ...array_map(fn ($rules) => ['sometimes', ...$rules], $this->rules()),
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new BranchResource($this->structure->updateBranch($branch, $data));
    }

    public function archive(Request $request, Branch $branch): BranchResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new BranchResource($this->structure->archiveBranch($branch, $data['reason'] ?? null));
    }

    public function restore(Branch $branch): BranchResource
    {
        return new BranchResource($this->structure->restoreBranch($branch));
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
