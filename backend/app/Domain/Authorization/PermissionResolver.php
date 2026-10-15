<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final class PermissionResolver
{
    /**
     * Tenant permissions of a tenant user in one organization. Must run inside that
     * organization's tenant context (role tables are protected by RLS).
     *
     * @return list<string>
     */
    public function tenantPermissions(User $user, string $organizationId): array
    {
        if (! $user->isTenantUser()) {
            return [];
        }

        return DB::table('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.organization_id', $organizationId)
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

    public function dataScope(User $user, string $organizationId): DataScope
    {
        $rows = DB::table('user_data_scopes')
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->get(['scope_type', 'department_id', 'location_id']);

        return new DataScope(
            organizationWide: $rows->contains('scope_type', 'organization'),
            departmentIds: $rows->whereNotNull('department_id')->pluck('department_id')->values()->all(),
            locationIds: $rows->whereNotNull('location_id')->pluck('location_id')->values()->all(),
        );
    }
}
