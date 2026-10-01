<?php

namespace App\Policies;

use App\Models\SecurityLog;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\Response;

class SecurityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('audit.view', TenantScope::getCurrentOrganizationId(), TenantScope::getCurrentProjectId());
    }

    public function view(User $user, SecurityLog $securityLog): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        if (!$user->hasPermission('audit.view', $organizationId, TenantScope::getCurrentProjectId())) {
            return false;
        }

        if ($securityLog->organization_id && $securityLog->organization_id !== $organizationId) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SecurityLog $securityLog): bool
    {
        return false;
    }

    public function delete(User $user, SecurityLog $securityLog): bool
    {
        return false;
    }

    public function restore(User $user, SecurityLog $securityLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, SecurityLog $securityLog): bool
    {
        return false;
    }
}
