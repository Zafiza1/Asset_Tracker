<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return LocationFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'type',
        'address',
        'latitude',
        'longitude',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Assets currently sitting at this location (see AssetLocation, the
     * denormalized current-location pointer maintained by MovementService).
     */
    public function currentAssets(): HasMany
    {
        return $this->hasMany(AssetLocation::class);
    }

    public function movementsFrom(): HasMany
    {
        return $this->hasMany(Movement::class, 'from_location_id');
    }

    public function movementsTo(): HasMany
    {
        return $this->hasMany(Movement::class, 'to_location_id');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
