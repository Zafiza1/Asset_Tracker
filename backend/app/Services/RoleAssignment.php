<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guards role grants on memberships against privilege escalation: nobody can
 * grant a role above their own level, or manage a member who outranks them,
 * in the same organization/project context. Platform Admin is never
 * assignable through memberships.
 */
class RoleAssignment
{
    public function assertAssignable(User $actor, string $roleSlug, int $organizationId, ?int $projectId = null): Role
    {
        $excluded = $projectId ? ['platform-admin', 'organization-owner'] : ['platform-admin'];
        $role = Role::where('slug', $roleSlug)->first();

        if (!$role || in_array($role->slug, $excluded, true)) {
            throw ApiException::invalid('Validation failed', [
                'role' => ["Role [{$roleSlug}] cannot be assigned at " . ($projectId ? 'project' : 'organization') . ' level'],
            ]);
        }

        if ($role->level > $actor->highestRoleLevel($organizationId, $projectId)) {
            throw ApiException::forbidden("You cannot assign a role above your own ([{$roleSlug}])");
        }

        return $role;
    }

    public function assertCanManage(User $actor, User $member, int $organizationId, ?int $projectId = null): void
    {
        if ($member->highestRoleLevel($organizationId, $projectId) > $actor->highestRoleLevel($organizationId, $projectId)) {
            throw ApiException::forbidden('You cannot manage a member whose role is above your own');
        }
    }

    /**
     * Role slugs granted exactly at this context, per user id — for member
     * listings without an N+1 query.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, array<int, string>>
     */
    public function rolesByUser(array $userIds, int $organizationId, ?int $projectId = null): Collection
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $userIds)
            ->where('user_roles.organization_id', $organizationId)
            ->where('user_roles.project_id', $projectId)
            ->get(['user_roles.user_id', 'roles.slug'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('slug')->all());
    }
}
