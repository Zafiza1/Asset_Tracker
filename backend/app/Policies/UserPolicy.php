<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        // Platform admin can view all users
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users with permission can view users
        return $user->hasPermission('user.view');
    }

    /**
     * Determine whether the user can view the user.
     */
    public function view(User $user, User $targetUser): bool
    {
        // Platform admin can view any user
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can view their own profile
        if ($user->id === $targetUser->id) {
            return true;
        }

        // Users with permission can view other users
        return $user->hasPermission('user.view');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        // Platform admin can create users
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users with permission can create users
        return $user->hasPermission('user.manage');
    }

    /**
     * Determine whether the user can update the user.
     */
    public function update(User $user, User $targetUser): bool
    {
        // Platform admin can update any user
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users can update their own profile
        if ($user->id === $targetUser->id) {
            return true;
        }

        // Users with permission can update other users
        return $user->hasPermission('user.manage');
    }

    /**
     * Determine whether the user can delete the user.
     */
    public function delete(User $user, User $targetUser): bool
    {
        // Platform admin can delete any user
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users cannot delete themselves
        if ($user->id === $targetUser->id) {
            return false;
        }

        // Users with permission can delete other users
        return $user->hasPermission('user.delete');
    }

    /**
     * Determine whether the user can manage user roles.
     */
    public function manageRoles(User $user, User $targetUser): bool
    {
        // Platform admin can manage roles for any user
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Users with permission can manage roles
        return $user->hasPermission('role.manage');
    }

    /**
     * Determine whether the user can restore the user.
     */
    public function restore(User $user, User $targetUser): bool
    {
        return $this->delete($user, $targetUser);
    }

    /**
     * Determine whether the user can permanently delete the user.
     */
    public function forceDelete(User $user, User $targetUser): bool
    {
        // Only platform admin can permanently delete
        return $user->isPlatformAdmin();
    }
}
