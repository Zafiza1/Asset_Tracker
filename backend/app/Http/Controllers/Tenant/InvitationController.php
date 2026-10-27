<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Services\CrossOrganizationMembers;
use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Invitations of the current organization ("Tambah pengguna"). Creating one never adds
 * anybody: the owner of the email address must accept it (see OrganizationInvitations).
 * The response is the same whether or not the address already has an account.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly OrganizationInvitations $invitations,
        private readonly CrossOrganizationMembers $members,
        private readonly Tenancy $tenancy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(OrganizationInvitation::STATUSES)],
        ]);

        $query = OrganizationInvitation::query()->with('inviter')
            ->when($request->query('search'), fn ($q, $term) => $q->where('email', 'ilike', ListQuery::like($term)))
            ->when($request->query('status'), fn ($q, $status) => $q->withEffectiveStatus($status));
        ListQuery::sort($query, $request, ['email' => 'email', 'created_at' => 'created_at', 'expires_at' => 'expires_at'], '-created_at');

        $page = $query->paginate(ListQuery::perPage($request))->withQueryString();
        $this->invitations->withRoles($page->items());

        return OrganizationInvitationResource::collection($page);
    }

    /**
     * Without "memberships" the address is invited to the current organization only. With it,
     * one invitation is created in every listed organization (each with its own roles);
     * "scopes" then applies to the current organization, the others start organization-wide.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'employee_number' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'home_department_id' => ['nullable', 'uuid'],
            'license_code_id' => ['required', 'uuid'],
            'role_ids' => ['required_without:memberships', 'array', 'min:1'],
            'role_ids.*' => ['uuid'],
            'memberships' => ['sometimes', 'array', 'min:1'],
            'memberships.*.organization_id' => ['required', 'uuid', 'distinct'],
            'memberships.*.role_ids' => ['required', 'array', 'min:1'],
            'memberships.*.role_ids.*' => ['uuid'],
            'scopes' => ['required_without:memberships', 'array'],
            'scopes.*.scope_type' => ['required', Rule::in(UserDataScope::TYPES)],
            'scopes.*.ref_id' => ['nullable', 'uuid', 'required_unless:scopes.*.scope_type,organization'],
        ], [
            'memberships.*.role_ids.required' => 'Pilih minimal satu role untuk setiap organisasi.',
            'memberships.*.role_ids.min' => 'Pilih minimal satu role untuk setiap organisasi.',
        ]);

        $currentId = $this->tenancy->organizationId();
        $profile = array_intersect_key($data, array_flip(['employee_number', 'job_title', 'home_department_id']));
        $grants = isset($data['memberships'])
            ? array_map(fn (array $m) => [
                ...$m,
                ...($m['organization_id'] === $currentId && isset($data['scopes']) ? ['scopes' => $data['scopes']] : []),
            ], $data['memberships'])
            : [['organization_id' => $currentId, 'role_ids' => $data['role_ids'], 'scopes' => $data['scopes']]];

        $invitations = $this->members->invite($this->tenancy->tenant()->user, $data['email'], $profile, $grants, $data['license_code_id'] ?? null);
        $current = $invitations[$currentId] ?? null;
        $current?->load('inviter', 'licenseCode');

        // data describes the current organization's invitation; null when only others were invited.
        return response()->json([
            'data' => $current ? (new OrganizationInvitationResource($current))->resolve($request) : null,
            'meta' => ['organization_ids' => array_keys($invitations)],
        ], 201);
    }

    public function resend(OrganizationInvitation $invitation): OrganizationInvitationResource
    {
        return $this->resource($this->invitations->resend($invitation));
    }

    public function revoke(OrganizationInvitation $invitation): OrganizationInvitationResource
    {
        return $this->resource($this->invitations->revoke($invitation));
    }

    private function resource(OrganizationInvitation $invitation): OrganizationInvitationResource
    {
        $this->invitations->withRoles([$invitation]);

        return new OrganizationInvitationResource($invitation->load('inviter'));
    }
}
