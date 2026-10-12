<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A generic definition adopted by an organization. The adopted generic definition and the
 * code never change (DB trigger); structure lives in versions pinned to generic versions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $generic_definition_id
 * @property string|null $tenant_category_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class TenantAssetDefinition extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['tenant_category_id', 'name', 'description', 'status'];

    public function genericDefinition(): BelongsTo
    {
        return $this->belongsTo(GenericAssetDefinition::class, 'generic_definition_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantAssetCategory::class, 'tenant_category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TenantDefinitionVersion::class, 'tenant_definition_id');
    }

    /** Latest released version: the one new assets are registered against (see GenericAssetDefinition::currentVersion). */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(TenantDefinitionVersion::class, 'tenant_definition_id')
            ->where('status', TenantDefinitionVersion::RELEASED)
            ->orderByDesc('version_number');
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(TenantDefinitionVersion::class, 'tenant_definition_id')->where('status', TenantDefinitionVersion::DRAFT);
    }
}
