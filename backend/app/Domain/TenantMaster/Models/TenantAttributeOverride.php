<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant override of selected properties of one generic attribute (see OverridePolicy).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tenant_definition_version_id
 * @property string $generic_attribute_key
 * @property array<string, mixed> $overrides
 */
class TenantAttributeOverride extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['generic_attribute_key', 'overrides'];

    protected function casts(): array
    {
        return ['overrides' => 'array'];
    }
}
