<?php

namespace App\Domain\Organization\Models;

use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $parent_id
 * @property string $location_type_id
 * @property string $code
 * @property string $name
 * @property string $path
 * @property string $status
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = ['branch_id', 'parent_id', 'location_type_id', 'code', 'name', 'address', 'status'];

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }

    protected static function booted(): void
    {
        // HasUuids assigns the id in its own creating hook, which is registered first.
        static::creating(function (Location $location): void {
            $parentPath = $location->parent_id ? self::query()->whereKey($location->parent_id)->value('path') : null;
            $location->path = ($parentPath ?: '/').$location->id.'/';
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function locationType(): BelongsTo
    {
        return $this->belongsTo(LocationType::class);
    }
}
