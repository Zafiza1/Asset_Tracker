<?php

namespace App\Modules\Delivery;

use App\Models\Project;
use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\ModulePolicy;

/**
 * Permission-based access to deliveries. Dispatching, delivering, returning
 * and cancelling all change a delivery, so they require delivery.update.
 */
class DeliveryPolicy extends ModulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'delivery.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->allowed($user, 'delivery.view', $delivery->organization_id, $delivery->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'delivery.create', $project->organization_id, $project->id);
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $this->allowed($user, 'delivery.update', $delivery->organization_id, $delivery->project_id);
    }
}
