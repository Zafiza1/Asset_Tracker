<?php

namespace App\Modules\Inspection;

use App\Models\Project;
use App\Models\User;
use App\Modules\Inspection\Models\Inspection;
use App\Modules\ModulePolicy;

/**
 * inspection.view   — read inspections and checklists
 * inspection.create — perform inspections: schedule them and record results
 * inspection.update — manage: reschedule/cancel inspections, edit checklists
 */
class InspectionPolicy extends ModulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'inspection.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Inspection $inspection): bool
    {
        return $this->allowed($user, 'inspection.view', $inspection->organization_id, $inspection->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'inspection.create', $project->organization_id, $project->id);
    }

    public function record(User $user, Inspection $inspection): bool
    {
        return $this->allowed($user, 'inspection.create', $inspection->organization_id, $inspection->project_id);
    }

    public function update(User $user, Inspection $inspection): bool
    {
        return $this->allowed($user, 'inspection.update', $inspection->organization_id, $inspection->project_id);
    }

    public function manageChecklists(User $user, Project $project): bool
    {
        return $this->allowed($user, 'inspection.update', $project->organization_id, $project->id);
    }
}
