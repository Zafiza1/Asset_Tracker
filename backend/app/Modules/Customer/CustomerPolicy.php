<?php

namespace App\Modules\Customer;

use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\ModulePolicy;

/**
 * Permission-based access to customers, using the permissions the module
 * declares in its manifest (config/modules.php).
 */
class CustomerPolicy extends ModulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->allowed($user, 'customer.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->allowed($user, 'customer.view', $customer->organization_id, $customer->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allowed($user, 'customer.create', $project->organization_id, $project->id);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->allowed($user, 'customer.update', $customer->organization_id, $customer->project_id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->allowed($user, 'customer.delete', $customer->organization_id, $customer->project_id);
    }
}
