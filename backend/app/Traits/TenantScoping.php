<?php

namespace App\Traits;

use App\Models\Scopes\TenantScope;

/**
 * Registers the automatic tenant global scope (see App\Models\Scopes\TenantScope)
 * on any model that has an organization_id and/or project_id column.
 *
 * Models that need one-off/manual scoping (e.g. Project::forOrganization()) keep
 * defining their own local scope methods alongside this trait.
 */
trait TenantScoping
{
    protected static function bootTenantScoping(): void
    {
        static::addGlobalScope(new TenantScope());
    }
}
