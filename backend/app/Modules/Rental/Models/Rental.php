<?php

namespace App\Modules\Rental\Models;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset rented to a customer for a period. Owned by the Rental module;
 * tenant-scoped like every project resource.
 */
class Rental extends Model
{
    use TenantScoping;

    public const STATUSES = ['reserved', 'active', 'returned', 'cancelled'];

    /** Rentals that still hold their asset (an asset can be in only one). */
    public const OPEN_STATUSES = ['reserved', 'active'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'customer_id',
        'asset_id',
        'reference',
        'status',
        'starts_at',
        'due_at',
        'checked_out_at',
        'returned_at',
        'cancelled_at',
        'destination_location_id',
        'returned_to_location_id',
        'daily_rate',
        'late_fee_per_day',
        'rented_days',
        'days_late',
        'rental_amount',
        'late_fee',
        'notes',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'starts_at' => 'datetime',
        'due_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'daily_rate' => 'decimal:2',
        'late_fee_per_day' => 'decimal:2',
        'rental_amount' => 'decimal:2',
        'late_fee' => 'decimal:2',
        'rented_days' => 'integer',
        'days_late' => 'integer',
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

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function returnedTo(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'returned_to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' && $this->due_at?->isPast();
    }
}
