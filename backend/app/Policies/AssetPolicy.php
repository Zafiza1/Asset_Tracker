<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssetPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view assets within the given project.
     */
    public function viewAny(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('asset.view', $project->organization_id, $project->id);
    }

    /**
     * Determine whether the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($asset->project_id)) {
            return false;
        }

        return $user->hasPermission('asset.view', $asset->organization_id, $asset->project_id);
    }

    /**
     * Determine whether the user can create an asset in the given project.
     */
    public function create(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('asset.create', $project->organization_id, $project->id);
    }

    /**
     * Determine whether the user can update the asset.
     */
    public function update(User $user, Asset $asset): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($asset->project_id)) {
            return false;
        }

        return $user->hasPermission('asset.update', $asset->organization_id, $asset->project_id);
    }

    /**
     * Determine whether the user can delete the asset.
     */
    public function delete(User $user, Asset $asset): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($asset->project_id)) {
            return false;
        }

        return $user->hasPermission('asset.delete', $asset->organization_id, $asset->project_id);
    }

    public function restore(User $user, Asset $asset): bool
    {
        return $this->delete($user, $asset);
    }

    public function forceDelete(User $user, Asset $asset): bool
    {
        return $user->isPlatformAdmin();
    }
}
