<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Integration abilities map onto the Section 22 permission set:
 * integration.view / connect / disconnect / configure.
 */
class IntegrationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowedInProject($user, 'integration.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Integration $integration): bool
    {
        return $this->allowed($user, 'integration.view', $integration);
    }

    /**
     * Adding an integration to a project is connecting it.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->allowedInProject($user, 'integration.connect', $project->organization_id, $project->id);
    }

    public function update(User $user, Integration $integration): bool
    {
        return $this->allowed($user, 'integration.configure', $integration);
    }

    /**
     * Removing an integration from a project is disconnecting it.
     */
    public function delete(User $user, Integration $integration): bool
    {
        return $this->allowed($user, 'integration.disconnect', $integration);
    }

    public function connect(User $user, Integration $integration): bool
    {
        return $this->allowed($user, 'integration.connect', $integration);
    }

    public function disconnect(User $user, Integration $integration): bool
    {
        return $this->allowed($user, 'integration.disconnect', $integration);
    }

    public function restore(User $user, Integration $integration): bool
    {
        return $this->delete($user, $integration);
    }

    public function forceDelete(User $user, Integration $integration): bool
    {
        return $user->isPlatformAdmin();
    }

    protected function allowed(User $user, string $permission, Integration $integration): bool
    {
        return $this->allowedInProject($user, $permission, $integration->organization_id, $integration->project_id);
    }

    protected function allowedInProject(User $user, string $permission, int $organizationId, int $projectId): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($projectId)) {
            return false;
        }

        return $user->hasPermission($permission, $organizationId, $projectId);
    }
}
