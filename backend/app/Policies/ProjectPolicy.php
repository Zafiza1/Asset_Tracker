<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any projects.
     */
    public function viewAny(User $user): bool
    {
        // Platform admin can view all projects
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can view projects they have access to
        return $user->projects()->exists();
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        // Platform admin can view any project
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can view projects they have access to
        return $user->canAccessProject($project->id);
    }

    /**
     * Determine whether the user can create projects.
     */
    public function create(User $user, ?Organization $organization = null): bool
    {
        // Platform admin can create projects
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Without a target organization: whether the user may create projects
        // in any organization they belong to
        if (!$organization) {
            return $user->hasPermissionAnywhere('project.create');
        }

        return $user->canAccessOrganization($organization->id)
            && $user->hasPermission('project.create', $organization->id);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        // Platform admin can update any project
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has access to project
        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('project.update', $project->organization_id, $project->id);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        // Platform admin can delete any project
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has access to project
        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('project.delete', $project->organization_id, $project->id);
    }

    /**
     * Determine whether the user can manage project users.
     */
    public function manageUsers(User $user, Project $project): bool
    {
        // Platform admin can manage users in any project
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has access to project
        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('project.manage-users', $project->organization_id, $project->id);
    }

    /**
     * Determine whether the user can restore the project.
     */
    public function restore(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }

    /**
     * Determine whether the user can permanently delete the project.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        // Only platform admin can permanently delete
        return $user->isPlatformAdmin();
    }
}
