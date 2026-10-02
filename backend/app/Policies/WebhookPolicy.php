<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\HandlesAuthorization;

class WebhookPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        return $user->hasPermission('webhook.view', $organizationId, $projectId);
    }

    public function view(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.view', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function create(User $user): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        return $user->hasPermission('webhook.create', $organizationId, $projectId);
    }

    public function update(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.update', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.delete', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function test(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.test', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function viewDeliveries(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.view', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function retryDeliveries(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.manage', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function toggleActive(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.update', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    public function regenerateSecret(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();
        
        if (!$user->hasPermission('webhook.update', $organizationId, $projectId)) {
            return false;
        }

        return $this->belongsToUserContext($user, $webhook);
    }

    protected function belongsToUserContext(User $user, Webhook $webhook): bool
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();

        if ($organizationId && $webhook->organization_id != $organizationId) {
            return false;
        }

        if ($projectId && $webhook->project_id && $webhook->project_id != $projectId) {
            return false;
        }

        if (!$projectId && $webhook->project_id) {
            return false;
        }

        return true;
    }
}
