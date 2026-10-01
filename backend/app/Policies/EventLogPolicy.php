<?php

namespace App\Policies;

use App\Models\EventLog;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\Response;

class EventLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('audit.view', TenantScope::getCurrentOrganizationId(), TenantScope::getCurrentProjectId());
    }

    public function view(User $user, EventLog $eventLog): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        if (!$user->hasPermission('audit.view', $organizationId, $projectId)) {
            return false;
        }

        if ($eventLog->organization_id && $eventLog->organization_id !== $organizationId) {
            return false;
        }

        if ($eventLog->project_id && $eventLog->project_id !== $projectId) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function delete(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function restore(User $user, EventLog $eventLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, EventLog $eventLog): bool
    {
        return false;
    }
}
