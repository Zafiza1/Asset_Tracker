<?php

namespace App\Modules\Inventory;

use App\Models\Project;
use App\Models\User;
use App\Modules\ModulePolicy;

/**
 * inventory.view   — stock levels, minimums and stock counts
 * inventory.adjust — set minimums; open, scan, complete and cancel counts
 */
class InventoryPolicy extends ModulePolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->allowed($user, 'inventory.view', $project->organization_id, $project->id);
    }

    public function adjust(User $user, Project $project): bool
    {
        return $this->allowed($user, 'inventory.adjust', $project->organization_id, $project->id);
    }
}
