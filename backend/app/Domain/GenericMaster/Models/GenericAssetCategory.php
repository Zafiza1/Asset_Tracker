<?php

namespace App\Domain\GenericMaster\Models;

use App\Domain\Shared\Masters\MaterializedPath;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $path
 * @property string $status
 */
class GenericAssetCategory extends Model
{
    use HasUuids;

    protected $fillable = ['parent_id', 'code', 'name', 'description', 'sort_order', 'status'];

    protected static function booted(): void
    {
        static::creating(function (GenericAssetCategory $category): void {
            $parentPath = $category->parent_id ? self::query()->whereKey($category->parent_id)->value('path') : null;
            $category->path = MaterializedPath::pathFor($category, $parentPath);
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function definitions(): HasMany
    {
        return $this->hasMany(GenericAssetDefinition::class, 'category_id');
    }

    /** True when $other is this category or one of its descendants. */
    public function contains(GenericAssetCategory $other): bool
    {
        return str_starts_with($other->path, $this->path);
    }
}
