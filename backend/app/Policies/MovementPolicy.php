<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Movements are an append-only history log scoped to a project (accessed via
 * an asset, which is itself already tenant-resolved) — there is no view/
 * update/delete-by-instance ability, only viewAny/create against the project.
 */
class MovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('movement.view', $project->organization_id, $project->id);
    }

    public function create(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('movement.create', $project->organization_id, $project->id);
    }
}
