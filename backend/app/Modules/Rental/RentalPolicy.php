<?php

namespace App\Modules\Rental;

use App\Models\Project;
use App\Models\User;
use App\Modules\ModulePolicy;
use App\Modules\Rental\Models\Rental;

/**
 * rental.view   — read rentals
 * rental.create — reserve (and hand over at once)
 * rental.update — check out, extend, take back, cancel
 */
class RentalPolicy extends ModulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'rental.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Rental $rental): bool
    {
        return $this->allowed($user, 'rental.view', $rental->organization_id, $rental->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'rental.create', $project->organization_id, $project->id);
    }

    /** Check out a new rental in the same request as creating it. */
    public function handOver(User $user, Project $project): bool
    {
        return $this->allowed($user, 'rental.update', $project->organization_id, $project->id);
    }

    public function update(User $user, Rental $rental): bool
    {
        return $this->allowed($user, 'rental.update', $rental->organization_id, $rental->project_id);
    }
}
