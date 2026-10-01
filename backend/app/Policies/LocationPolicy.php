<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LocationPolicy
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

        return $user->hasPermission('location.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Location $location): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($location->project_id)) {
            return false;
        }

        return $user->hasPermission('location.view', $location->organization_id, $location->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('location.create', $project->organization_id, $project->id);
    }

    public function update(User $user, Location $location): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($location->project_id)) {
            return false;
        }

        return $user->hasPermission('location.update', $location->organization_id, $location->project_id);
    }

    public function delete(User $user, Location $location): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($location->project_id)) {
            return false;
        }

        return $user->hasPermission('location.delete', $location->organization_id, $location->project_id);
    }

    public function restore(User $user, Location $location): bool
    {
        return $this->delete($user, $location);
    }

    public function forceDelete(User $user, Location $location): bool
    {
        return $user->isPlatformAdmin();
    }
}
