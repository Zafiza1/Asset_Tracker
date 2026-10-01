<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrganizationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any organizations.
     */
    public function viewAny(User $user): bool
    {
        // Platform admin can view all organizations
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can view organizations they belong to
        return $user->organizations()->exists();
    }

    /**
     * Determine whether the user can view the organization.
     */
    public function view(User $user, Organization $organization): bool
    {
        // Platform admin can view any organization
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can view organizations they belong to
        return $user->canAccessOrganization($organization->id);
    }

    /**
     * Determine whether the user can create organizations.
     */
    public function create(User $user): bool
    {
        // Platform admin can create organizations; so can anyone when
        // self-service onboarding is on (config/platform.php)
        return $user->isPlatformAdmin()
            || $user->hasPermission('organization.create')
            || config('platform.self_service_organizations');
    }

    /**
     * Determine whether the user can update the organization.
     */
    public function update(User $user, Organization $organization): bool
    {
        // Platform admin can update any organization
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has permission and belongs to organization
        if (!$user->canAccessOrganization($organization->id)) {
            return false;
        }

        return $user->hasPermission('organization.update', $organization->id);
    }

    /**
     * Determine whether the user can delete the organization.
     */
    public function delete(User $user, Organization $organization): bool
    {
        // Platform admin can delete any organization
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has permission and belongs to organization
        if (!$user->canAccessOrganization($organization->id)) {
            return false;
        }

        return $user->hasPermission('organization.delete', $organization->id);
    }

    /**
     * Determine whether the user can manage organization users.
     */
    public function manageUsers(User $user, Organization $organization): bool
    {
        // Platform admin can manage users in any organization
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Check if user has permission and belongs to organization
        if (!$user->canAccessOrganization($organization->id)) {
            return false;
        }

        return $user->hasPermission('organization.manage-users', $organization->id);
    }

    /**
     * Determine whether the user can restore the organization.
     */
    public function restore(User $user, Organization $organization): bool
    {
        return $this->delete($user, $organization);
    }

    /**
     * Determine whether the user can permanently delete the organization.
     */
    public function forceDelete(User $user, Organization $organization): bool
    {
        // Only platform admin can permanently delete
        return $user->isPlatformAdmin();
    }
}
