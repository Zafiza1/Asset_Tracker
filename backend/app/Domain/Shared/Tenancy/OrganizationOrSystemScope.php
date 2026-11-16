<?php

namespace App\Domain\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** The active tenant's rows plus the system rows (organization_id NULL) every tenant shares. */
final class OrganizationOrSystemScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->hasTenant()) {
            $column = $model->qualifyColumn('organization_id');
            $builder->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $tenancy->organizationId()));

            return;
        }

        if (! $tenancy->isUnrestricted()) {
            throw new MissingTenantContext;
        }
    }
}
