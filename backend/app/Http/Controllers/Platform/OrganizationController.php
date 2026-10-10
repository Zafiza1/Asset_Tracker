<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class OrganizationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Organization::STATUS_ACTIVE, Organization::STATUS_SUSPENDED, Organization::STATUS_ARCHIVED])],
        ]);

        $query = Organization::query()
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('code', 'ilike', ListQuery::like($term))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        ListQuery::sort($query, $request, ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'], 'name');

        return OrganizationResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    public function store(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,29}$/', Rule::unique('organizations', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'admin.password' => ['required', 'string', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $admin = $data['admin'];
        unset($data['admin']);
        $result = $provisioner->provision(array_filter($data, fn ($v) => $v !== null), $admin);

        return (new OrganizationResource($result['organization']))
            ->additional(['meta' => ['admin_user_id' => $result['admin']->id]])
            ->response()->setStatusCode(201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization);
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($organization, $data) {
            $before = $organization->only(array_keys($data));
            $organization->fill($data)->save();
            [$b, $a] = AuditLogger::diff($before, $organization->only(array_keys($data)));
            if ($a !== []) {
                $this->audit->record('organization.updated', $organization, before: $b, after: $a, organizationId: $organization->id);
            }
        });

        return new OrganizationResource($organization);
    }

    public function suspend(Request $request, Organization $organization): OrganizationResource
    {
        return $this->changeStatus($request, $organization, Organization::STATUS_SUSPENDED);
    }

    public function activate(Request $request, Organization $organization): OrganizationResource
    {
        return $this->changeStatus($request, $organization, Organization::STATUS_ACTIVE);
    }

    private function changeStatus(Request $request, Organization $organization, string $status): OrganizationResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($organization->status === Organization::STATUS_ARCHIVED) {
            throw ApiException::conflict('ORGANIZATION_ARCHIVED', 'Organisasi yang diarsipkan tidak dapat diubah statusnya.');
        }

        DB::transaction(function () use ($organization, $status, $data) {
            $before = $organization->status;
            $organization->forceFill(['status' => $status])->save();
            $this->audit->record('organization.status_changed', $organization,
                before: ['status' => $before], after: ['status' => $status],
                metadata: ['reason' => $data['reason']], organizationId: $organization->id);
        });

        return new OrganizationResource($organization);
    }
}
