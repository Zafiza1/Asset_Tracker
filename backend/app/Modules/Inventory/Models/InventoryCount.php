<?php

namespace App\Modules\Inventory\Models;

use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock count (cycle count) of one location: which recorded assets were
 * found, which are missing and which turned up unexpectedly.
 */
class InventoryCount extends Model
{
    use TenantScoping;

    public const STATUSES = ['open', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'location_id',
        'status',
        'completed_at',
        'summary',
        'notes',
        'created_by',
        'completed_by',
    ];

    protected $casts = [
        'summary' => 'array',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
