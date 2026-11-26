<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Services\CrossOrganizationMembers;
use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\Organization\Models\Employee;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
        ListQuery::sort($query, $request, [
            'email' => 'email', 'created_at' => 'created_at', 'expires_at' => 'expires_at', 'expires' => 'expires_at',
            // Effective status: a pending invitation past its expiry counts as expired.
            'status' => DB::raw("case when status = 'pending' and expires_at <= now() then 'expired' else status end"),
            'invited_by' => ListQuery::related(new OrganizationInvitation, 'inviter', 'name'),
            'roles' => DB::table('roles')->selectRaw("string_agg(roles.name, ', ' order by roles.name)")
                ->whereRaw('roles.id::text in (select jsonb_array_elements_text(organization_invitations.role_ids))'),
        ], '-created_at');

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
            // Karyawan the account is for: its code becomes the employee number, its division the job title.
            'employee_id' => ['nullable', 'uuid'],
            'employee_number' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'home_department_id' => ['nullable', 'uuid'],
            'license_code_id' => [config('edition.licensing') ? 'required' : 'nullable', 'uuid'],
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
            'memberships.*.role_ids.required' => 'Pilih minimal satu role untuk setiap business unit.',
            'memberships.*.role_ids.min' => 'Pilih minimal satu role untuk setiap business unit.',
        ]);

        $currentId = $this->tenancy->organizationId();
        $profile = array_intersect_key($data, array_flip(['employee_number', 'job_title', 'home_department_id']));
        if (isset($data['employee_id'])) {
            $profile = [...$profile, ...$this->employeeProfile($data['employee_id'])];
        }
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

    /**
     * Active employees that can still be invited, searched by code or name (at most 20): not yet a
     * member and without an invitation waiting for an answer. For "Undang Pengguna".
     */
    public function employeeOptions(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $members = OrganizationMember::query()->whereNotNull('employee_number')->select('employee_number');
        $pending = OrganizationInvitation::query()->withEffectiveStatus(OrganizationInvitation::STATUS_PENDING)
            ->whereNotNull('employee_number')->select('employee_number');
        $employees = Employee::query()->with('department')->where('status', MasterStatus::ACTIVE)
            ->whereNotIn('code', $members)->whereNotIn('code', $pending)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w->where('code', 'ilike', ListQuery::like($term))->orWhere('name', 'ilike', ListQuery::like($term))))
            ->orderBy('name')->limit(20)->get();

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'division' => $e->department?->name,
        ])->values()]);
    }

    /**
     * The membership fields that come from an employee of this organization.
     *
     * @return array{employee_number: string, job_title: string|null}
     */
    private function employeeProfile(string $employeeId): array
    {
        $employee = Employee::query()->with('department')->whereKey($employeeId)->where('status', MasterStatus::ACTIVE)->first()
            ?? throw ApiException::unprocessable('VALIDATION_FAILED', 'Karyawan tidak ditemukan atau tidak aktif.', ['fields' => ['employee_id' => ['Karyawan tidak ditemukan atau tidak aktif.']]]);
        if (OrganizationMember::query()->where('employee_number', $employee->code)->exists()) {
            throw ApiException::unprocessable('VALIDATION_FAILED', "{$employee->name} sudah memiliki akun pengguna.", ['fields' => ['employee_id' => ["{$employee->name} sudah memiliki akun pengguna."]]]);
        }

        return ['employee_number' => $employee->code, 'job_title' => $employee->department?->name];
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
