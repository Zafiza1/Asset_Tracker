<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final class PermissionResolver
{
    /**
     * Tenant permissions of a tenant user. Must run inside that user's tenant context
     * (role tables are protected by RLS).
     *
     * @return list<string>
     */
    public function tenantPermissions(User $user): array
    {
        if (! $user->isTenantUser()) {
            return [];
        }

        return DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.organization_id', $user->organization_id)
            ->where('permissions.scope', PermissionCatalog::SCOPE_TENANT)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    /** @return list<string> */
    public function platformPermissions(User $user): array
    {
        if (! $user->isPlatformUser()) {
            return [];
        }

        return DB::table('platform_user_roles')
            ->join('platform_role_permissions', 'platform_role_permissions.platform_role_id', '=', 'platform_user_roles.platform_role_id')
            ->join('permissions', 'permissions.id', '=', 'platform_role_permissions.permission_id')
            ->where('platform_user_roles.user_id', $user->id)
            ->where('permissions.scope', PermissionCatalog::SCOPE_PLATFORM)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    public function dataScope(User $user): DataScope
    {
        $rows = DB::table('user_data_scopes')
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->get(['scope_type', 'branch_id', 'department_id', 'location_id']);

        return new DataScope(
            organizationWide: $rows->contains('scope_type', 'organization'),
            branchIds: $rows->whereNotNull('branch_id')->pluck('branch_id')->values()->all(),
            departmentIds: $rows->whereNotNull('department_id')->pluck('department_id')->values()->all(),
            locationIds: $rows->whereNotNull('location_id')->pluck('location_id')->values()->all(),
        );
    }
}
