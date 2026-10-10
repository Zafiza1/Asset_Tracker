<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models whose rows are owned by one organization.
 *
 * - Reads are filtered to the active tenant; with no context at all they throw (fail-closed).
 * - Creates take organization_id from the tenant context; a different value is rejected.
 * - organization_id can never be changed after creation.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $tenancy = app(Tenancy::class);

            if ($tenancy->hasTenant()) {
                $orgId = $tenancy->organizationId();
                if ($model->organization_id === null) {
                    $model->organization_id = $orgId;
                } elseif ($model->organization_id !== $orgId) {
                    throw new LogicException('Cannot create a record for another organization.');
                }

                return;
            }

            if (! $tenancy->isUnrestricted()) {
                throw new MissingTenantContext;
            }
            if ($model->organization_id === null) {
                throw new LogicException('organization_id is required outside a tenant context.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new LogicException('organization_id is immutable.');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
