<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\Services\RoleManagement;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private readonly RoleManagement $roles) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->with('permissions')->withCount('users')->orderBy('name')->get()
        );
    }

    public function store(Request $request, Tenancy $tenancy): RoleResource
    {
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                Rule::unique('roles', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        $role = $this->roles->create($data, $data['permissions']);

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(Request $request, Role $role): RoleResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        $role = $this->roles->update($role, array_intersect_key($data, array_flip(['name', 'description'])), $data['permissions'] ?? null);

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function destroy(Role $role): Response
    {
        $this->roles->delete($role);

        return response()->noContent();
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('scope', PermissionCatalog::SCOPE_TENANT)
            ->orderBy('group')->orderBy('code')
            ->get(['code', 'group', 'description']);

        return response()->json(['data' => $permissions]);
    }
}
