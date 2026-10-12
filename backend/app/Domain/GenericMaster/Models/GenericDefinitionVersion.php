<?php

namespace App\Domain\GenericMaster\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * draft -> released -> deprecated. Released versions (and their attributes) are immutable,
 * enforced by service checks and by a database trigger.
 *
 * @property string $id
 * @property string $definition_id
 * @property int $version_number
 * @property string $status
 * @property string|null $changelog
 * @property string|null $migration_notes
 * @property string|null $schema_hash
 * @property Carbon|null $released_at
 */
class GenericDefinitionVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const RELEASED = 'released';

    public const DEPRECATED = 'deprecated';

    protected $table = 'generic_asset_definition_versions';

    protected $fillable = ['changelog', 'migration_notes'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
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
        return $this->belongsTo(GenericAssetDefinition::class, 'definition_id');
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(GenericAttributeDefinition::class, 'definition_version_id')->orderBy('sort_order')->orderBy('key');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
