<?php

namespace App\Modules\Delivery\Models;

use App\Models\Asset;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset in a delivery. Scoped through its delivery (no tenant columns of
 * its own); always reach it via Delivery::items().
 */
class DeliveryItem extends Model
{
    protected $fillable = [
        'delivery_id',
        'asset_id',
        'status',
        'delivered_at',
        'returned_at',
        'returned_to_location_id',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function returnedTo(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'returned_to_location_id');
    }
}
