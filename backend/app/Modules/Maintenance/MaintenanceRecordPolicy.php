<?php

namespace App\Modules\Maintenance;

use App\Models\Project;
use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceRecord;
use App\Modules\ModulePolicy;

/**
 * Permission-based access to maintenance records, using the permissions the
 * module declares in its manifest (config/modules.php).
 */
class MaintenanceRecordPolicy extends ModulePolicy
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
}
