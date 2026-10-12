<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asset condition of an organization: copied from a generic condition, or tenant-defined
 * (generic_condition_id NULL).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $generic_condition_id
 * @property string $code
 * @property string $name
 * @property int $severity
 * @property string $status
 */
class AssetCondition extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['code', 'name', 'description', 'severity', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['severity' => 'integer', 'sort_order' => 'integer'];
    }

    public function genericCondition(): BelongsTo
    {
        return $this->belongsTo(GenericCondition::class);
    }
}
