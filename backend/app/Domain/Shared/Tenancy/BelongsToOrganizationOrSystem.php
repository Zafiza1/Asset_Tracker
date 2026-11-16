<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models whose rows belong to one organization or, with organization_id NULL, to the
 * system (shared reference rows such as system dashboard templates).
 *
 * - Reads in a tenant context see that tenant's rows and the system rows; with no context
 *   at all they throw (fail-closed).
 * - Creates in a tenant context always belong to that tenant: system rows are created only
 *   in the system context (RLS enforces the same).
 * - organization_id can never be changed after creation.
 *
 * @mixin Model
 */
trait BelongsToOrganizationOrSystem
{
    public static function bootBelongsToOrganizationOrSystem(): void
    {
        static::addGlobalScope(new OrganizationOrSystemScope);

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

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }
}
