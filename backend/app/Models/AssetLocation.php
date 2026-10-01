<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\AssetLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current location pointer for an asset — one row per asset, kept in
 * sync by MovementService each time a Movement is recorded. See
 * database/migrations/2024_01_04_000003_create_asset_locations_table.php.
 */
class AssetLocation extends Model
{
    use HasFactory, TenantScoping;

    protected static function newFactory()
    {
        return AssetLocationFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'asset_id',
        'location_id',
        'source',
        'metadata',
        'arrived_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'arrived_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
