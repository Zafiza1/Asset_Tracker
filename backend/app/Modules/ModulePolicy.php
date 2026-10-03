<?php

namespace App\Modules;

use App\Models\User;

/**
 * Shared check for module policies: the user must belong to the project and
 * hold the permission the module's manifest declares there.
 */
abstract class ModulePolicy
{
    protected function allowed(User $user, string $permission, int $organizationId, int $projectId): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->canAccessProject($projectId)
            && $user->hasPermission($permission, $organizationId, $projectId);
    }
}
