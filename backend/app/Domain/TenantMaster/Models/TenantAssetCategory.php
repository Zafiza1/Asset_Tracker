<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Shared\Masters\MaterializedPath;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Organization-specific (sub)category, always anchored to one generic category
 * (e.g. "Mobil Operasional Direksi" under generic "Kendaraan"). Children share the anchor.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $parent_id
 * @property string $generic_category_id
 * @property string $code
 * @property string $name
 * @property string $path
 * @property string $status
 */
class TenantAssetCategory extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['parent_id', 'generic_category_id', 'code', 'name', 'description', 'status'];

    protected static function booted(): void
    {
        static::creating(function (TenantAssetCategory $category): void {
            $parentPath = $category->parent_id ? self::query()->whereKey($category->parent_id)->value('path') : null;
            $category->path = MaterializedPath::pathFor($category, $parentPath);
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function genericCategory(): BelongsTo
    {
        return $this->belongsTo(GenericAssetCategory::class, 'generic_category_id');
    }

    /** Custom fields defined on this category itself (not inherited ones). */
    public function customAttributes(): HasMany
    {
        return $this->hasMany(TenantCategoryAttribute::class, 'category_id');
    }
}
