<?php

namespace App\Modules\Inventory\Models;

use App\Models\Asset;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset in a stock count. Scoped through its count; reach it via
 * InventoryCount::items().
 *
 * expected + scanned = found; expected only = missing; scanned only = unexpected.
 */
class InventoryCountItem extends Model
{
    protected $fillable = [
        'inventory_count_id',
        'asset_id',
        'expected',
        'scanned',
        'scanned_at',
        'recorded_location_id',
        'reconciled',
    ];

    protected $casts = [
        'expected' => 'boolean',
        'scanned' => 'boolean',
        'reconciled' => 'boolean',
        'scanned_at' => 'datetime',
    ];

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function recordedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'recorded_location_id');
    }

    /** found | missing | unexpected */
    public function outcome(): string
    {
        return match (true) {
            $this->expected && $this->scanned => 'found',
            $this->expected => 'missing',
            default => 'unexpected',
        };
    }
}
