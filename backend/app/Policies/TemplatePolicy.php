<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Template Policy — templates are platform-level resources (Control Plane).
 * Only platform admins should manage templates in production.
 */
class TemplatePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any templates.
     */
    public function viewAny(User $user): bool
    {
        // Platform admin can view all templates
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Regular users can view available templates (for project creation)
        return true;
    }

    /**
     * Determine whether the user can view the template.
     */
    public function view(User $user, Template $template): bool
    {
        // Platform admin can view any template
        if ($user->isPlatformAdmin()) {
            return true;
        }

        // Regular users can view available templates
        return $template->isAvailable();
    }

    /**
     * Determine whether the user can create templates.
     */
    public function create(User $user): bool
    {
        // Only platform admin can create templates
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can update the template.
     */
    public function update(User $user, Template $template): bool
    {
        // Only platform admin can update templates
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can delete the template.
     */
    public function delete(User $user, Template $template): bool
    {
        // Only platform admin can delete templates
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can manage template versions.
     */
    public function manageVersions(User $user, Template $template): bool
    {
        // Only platform admin can manage template versions
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can associate modules with templates.
     */
    public function manageModules(User $user, Template $template): bool
    {
        // Only platform admin can manage template modules
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can deprecate a template.
     */
    public function deprecate(User $user, Template $template): bool
    {
        // Only platform admin can deprecate templates
        return $user->isPlatformAdmin();
    }
}
