<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A standard asset definition. Its structure lives in immutable released versions.
 *
 * @property string $id
 * @property string $category_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class GenericAssetDefinition extends Model
{
    use HasUuids;

    protected $fillable = ['category_id', 'code', 'name', 'description', 'status'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(GenericAssetCategory::class, 'category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GenericDefinitionVersion::class, 'definition_id');
    }

    /**
     * Latest released (non-deprecated) version: the one tenants adopt by default.
     * (ofMany() is not used: its max(id) tiebreaker does not exist for uuid in PostgreSQL;
     * eager loading keeps the first row per parent, i.e. the highest version.)
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(GenericDefinitionVersion::class, 'definition_id')
            ->where('status', GenericDefinitionVersion::RELEASED)
            ->orderByDesc('version_number');
    }
}
