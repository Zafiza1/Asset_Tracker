<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Module lifecycle actions within a project, each gated by its own
 * permission (Section 22). Upgrading counts as installing a new version, so
 * it requires module.install.
 */
class ProjectModulePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.view');
    }

    public function install(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.install');
    }

    public function configure(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.configure');
    }

    public function enable(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.enable');
    }

    public function disable(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.disable');
    }

    public function uninstall(User $user, Project $project): bool
    {
        return $this->allows($user, $project, 'module.uninstall');
    }

    protected function allows(User $user, Project $project, string $permission): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission($permission, $project->organization_id, $project->id);
    }
}
