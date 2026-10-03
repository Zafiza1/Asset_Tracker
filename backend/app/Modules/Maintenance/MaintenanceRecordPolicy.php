<?php

namespace App\Modules\Maintenance;

use App\Models\Project;
use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceRecord;

/**
 * Permission-based access to maintenance records, using the permissions the
 * module declares in its manifest (config/modules.php).
 */
class MaintenanceRecordPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'maintenance.view', $project->organization_id, $project->id);
    }

    public function view(User $user, MaintenanceRecord $record): bool
    {
        return $this->allowed($user, 'maintenance.view', $record->organization_id, $record->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'maintenance.create', $project->organization_id, $project->id);
    }

    public function update(User $user, MaintenanceRecord $record): bool
    {
        return $this->allowed($user, 'maintenance.update', $record->organization_id, $record->project_id);
    }

    public function complete(User $user, MaintenanceRecord $record): bool
    {
        return $this->allowed($user, 'maintenance.complete', $record->organization_id, $record->project_id);
    }

    protected function allowed(User $user, string $permission, int $organizationId, int $projectId): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->canAccessProject($projectId)
            && $user->hasPermission($permission, $organizationId, $projectId);
    }
}
