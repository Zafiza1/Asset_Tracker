<?php

namespace App\Modules\Delivery\Models;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assets sent to a customer, and their return. Owned by the Delivery module;
 * tenant-scoped like every project resource.
 */
class Delivery extends Model
{
    use TenantScoping;

    public const STATUSES = ['pending', 'in_transit', 'delivered', 'returned', 'cancelled'];

    /** Deliveries that still hold their assets (an asset can be in only one). */
    public const OPEN_STATUSES = ['pending', 'in_transit', 'delivered'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'customer_id',
        'reference',
        'status',
        'destination_location_id',
        'scheduled_at',
        'dispatched_at',
        'delivered_at',
        'returned_at',
        'cancelled_at',
        'received_by',
        'notes',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Includes soft-deleted customers, so history keeps resolving. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
