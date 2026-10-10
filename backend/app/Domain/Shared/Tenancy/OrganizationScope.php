<?php

namespace App\Domain\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->hasTenant()) {
            $builder->where($model->qualifyColumn('organization_id'), $tenancy->organizationId());

            return;
        }

        if (! $tenancy->isUnrestricted()) {
            throw new MissingTenantContext;
        }
    }
}
