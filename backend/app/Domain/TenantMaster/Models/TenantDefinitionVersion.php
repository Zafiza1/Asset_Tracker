<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Version of a tenant definition, pinned to one generic version. On release the effective
 * schema (generic attributes + overrides + tenant attributes) is computed, hashed and
 * frozen; afterwards the version is immutable (only released -> deprecated).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tenant_definition_id
 * @property string $generic_definition_version_id
 * @property int $version_number
 * @property string $status
 * @property string|null $changelog
 * @property array<string, mixed>|null $effective_schema
 * @property string|null $schema_hash
 * @property Carbon|null $released_at
 */
class TenantDefinitionVersion extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DRAFT = 'draft';

    public const RELEASED = 'released';

    public const DEPRECATED = 'deprecated';

    protected $table = 'tenant_asset_definition_versions';

    protected $fillable = ['changelog'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_schema' => 'array',
            'released_at' => 'datetime',
            'deprecated_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TenantAssetDefinition::class, 'tenant_definition_id');
    }

    public function genericVersion(): BelongsTo
    {
        return $this->belongsTo(GenericDefinitionVersion::class, 'generic_definition_version_id');
    }

    public function tenantAttributes(): HasMany
    {
        return $this->hasMany(TenantAttributeDefinition::class, 'tenant_definition_version_id')->orderBy('sort_order')->orderBy('key');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(TenantAttributeOverride::class, 'tenant_definition_version_id')->orderBy('generic_attribute_key');
    }
}
