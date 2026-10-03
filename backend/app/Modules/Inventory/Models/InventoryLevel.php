<?php

namespace App\Modules\Inventory\Models;

use App\Models\Location;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimum number of assets (of a type, or of any type when asset_type is
 * null) that should be at a location.
 */
class InventoryLevel extends Model
{
    use TenantScoping;

    protected $fillable = [
        'organization_id',
        'project_id',
        'location_id',
        'asset_type',
        'min_quantity',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
