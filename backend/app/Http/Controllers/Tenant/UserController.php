<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CrossOrganizationMembers;
use App\Domain\Identity\Services\UserManagement;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\UserResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManagement $users,
        private readonly CrossOrganizationMembers $members,
        private readonly Tenancy $tenancy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED, User::STATUS_INVITED])],
            'role_id' => ['nullable', 'uuid'],
        ]);

        $query = User::query()->ofCurrentOrganization()->with('roles', 'membership')
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('email', 'ilike', ListQuery::like($term))
                ->orWhereHas('membership', fn ($m) => $m->where('employee_number', 'ilike', ListQuery::like($term)))))
            // Effective status: a blocked account overrides the membership status.
            ->when($request->query('status'), fn ($q, $status) => $status === User::STATUS_ACTIVE
                ? $q->where('status', $status)->whereHas('membership', fn ($m) => $m->where('status', $status))
                : $q->where(fn ($w) => $w->where('status', $status)->orWhereHas('membership', fn ($m) => $m->where('status', $status))))
            ->when($request->query('role_id'), fn ($q, $roleId) => $q->whereHas('roles', fn ($r) => $r->whereKey($roleId)));

        ListQuery::sort($query, $request, [
            'name' => 'name', 'email' => 'email',
            'status' => OrganizationMember::query()->select('status')->whereColumn('organization_members.user_id', 'users.id'),
            'created_at' => 'created_at', 'last_login_at' => 'last_login_at',
        ], 'name');

        return UserResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    // New users are invited (InvitationController); nobody is added to an organization directly.

    /** Organizations where the actor may invite users, each with its roles (current organization first). */
    public function organizationOptions(): JsonResponse
    {
        $currentId = $this->tenancy->organizationId();

        return response()->json(['data' => $this->members->options($this->tenancy->tenant()->user)->map(fn (array $row) => [
            ...$this->organizationSummary($row['organization'], $currentId),
            'roles' => $row['roles']->map(fn ($r) => [
                'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description, 'permissions_count' => $r->permissions_count,
            ])->values(),
        ])->values()]);
    }

    /** The organizations this user belongs to, limited to those the actor may view users in. */
    public function organizations(User $user): JsonResponse
    {
        $currentId = $this->tenancy->organizationId();

        return response()->json(['data' => $this->members->memberships($this->tenancy->tenant()->user, $user)->map(fn (array $row) => [
            ...$this->organizationSummary($row['organization'], $currentId),
            'status' => (new UserResource($row['member']))->resolve()['status'],
            'roles' => $row['member']->roles->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name])->values(),
        ])->values()]);
    }

    /** Invites this member of the current organization to another organization (access starts once they accept). */
    public function grantOrganization(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid'],
        ]);

        $invitation = $this->members->inviteMember($this->tenancy->tenant()->user, $user, $data['organization_id'], $data['role_ids']);

        return (new OrganizationInvitationResource($invitation))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles', 'dataScopes', 'membership'));
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            ...array_map(fn ($rules) => ['sometimes', ...$rules], $this->profileRules()),
            'email' => ['sometimes', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        return new UserResource($this->users->update($user, $data)->load('roles', 'dataScopes', 'membership'));
    }

    public function suspend(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_SUSPENDED)->load('membership'));
    }

    public function activate(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_ACTIVE)->load('membership'));
    }

    public function deactivate(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_DEACTIVATED)->load('membership'));
    }

    public function syncRoles(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['uuid'],
        ]);

        return new UserResource($this->users->syncRoles($user, $data['role_ids'])->load('roles', 'dataScopes', 'membership'));
    }

    public function syncScopes(Request $request, User $user): UserResource
    {
        $data = $request->validate($this->scopeRules());

        return new UserResource($this->users->syncScopes($user, $data['scopes'])->load('roles', 'dataScopes', 'membership'));
    }

    public function resetPassword(Request $request, User $user): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $this->users->resetPassword($user, $data['password']);

        return response()->noContent();
    }

    /** @return array<string, list<mixed>> */
    private function profileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'employee_number' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'home_department_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function scopeRules(): array
    {
        return [
            'scopes' => ['present', 'array'],
            'scopes.*.scope_type' => ['required', Rule::in(UserDataScope::TYPES)],
            'scopes.*.ref_id' => ['nullable', 'uuid', 'required_unless:scopes.*.scope_type,organization'],
        ];
    }

    /** @return array{id: string, code: string, name: string, is_current: bool} */
    private function organizationSummary(Organization $organization, string $currentId): array
    {
        return ['id' => $organization->id, 'code' => $organization->code, 'name' => $organization->name, 'is_current' => $organization->id === $currentId];
    }
}
