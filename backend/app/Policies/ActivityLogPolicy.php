<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\Response;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(
            'audit.view',
            TenantScope::getCurrentOrganizationId(),
            TenantScope::getCurrentProjectId(),
        );
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();

        if (!$user->hasPermission('audit.view', $organizationId, $projectId)) {
            return false;
        }

        if ($activityLog->organization_id && $activityLog->organization_id !== $organizationId) {
            return false;
        }

        if ($activityLog->project_id && $activityLog->project_id !== $projectId) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function delete(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function restore(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }
}
