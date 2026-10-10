<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserManagement;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private readonly UserManagement $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED, User::STATUS_INVITED])],
            'role_id' => ['nullable', 'uuid'],
        ]);

        $query = User::query()->ofCurrentOrganization()->with('roles')
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('email', 'ilike', ListQuery::like($term))
                ->orWhere('employee_number', 'ilike', ListQuery::like($term))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('role_id'), fn ($q, $roleId) => $q->whereHas('roles', fn ($r) => $r->whereKey($roleId)));

        ListQuery::sort($query, $request, [
            'name' => 'name', 'email' => 'email', 'status' => 'status', 'created_at' => 'created_at', 'last_login_at' => 'last_login_at',
        ], 'name');

        return UserResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    public function store(Request $request): UserResource
    {
        $data = $request->validate([
            ...$this->profileRules(),
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid'],
            ...$this->scopeRules(required: true),
        ]);

        $user = $this->users->create($data, $data['role_ids'], $data['scopes']);

        return new UserResource($user->load('roles', 'dataScopes'));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles', 'dataScopes'));
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            ...array_map(fn ($rules) => ['sometimes', ...$rules], $this->profileRules()),
            'email' => ['sometimes', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        return new UserResource($this->users->update($user, $data)->load('roles', 'dataScopes'));
    }

    public function suspend(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_SUSPENDED));
    }

    public function activate(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_ACTIVE));
    }

    public function deactivate(User $user): UserResource
    {
        return new UserResource($this->users->changeStatus($user, User::STATUS_DEACTIVATED));
    }

    public function syncRoles(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['uuid'],
        ]);

        return new UserResource($this->users->syncRoles($user, $data['role_ids'])->load('roles', 'dataScopes'));
    }

    public function syncScopes(Request $request, User $user): UserResource
    {
        $data = $request->validate($this->scopeRules(required: false));

        return new UserResource($this->users->syncScopes($user, $data['scopes'])->load('roles', 'dataScopes'));
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
            'home_branch_id' => ['nullable', 'uuid'],
            'home_department_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function scopeRules(bool $required): array
    {
        return [
            'scopes' => [$required ? 'required' : 'present', 'array'],
            'scopes.*.scope_type' => ['required', Rule::in(UserDataScope::TYPES)],
            'scopes.*.ref_id' => ['nullable', 'uuid', 'required_unless:scopes.*.scope_type,organization'],
        ];
    }
}
