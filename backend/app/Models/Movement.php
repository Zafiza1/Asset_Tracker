<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\MovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only movement history for an asset (Section 26). Rows are never
 * updated or deleted — corrections happen by recording a new movement.
 */
class Movement extends Model
{
    use HasFactory, TenantScoping;

    protected $table = 'asset_movements';

    protected static function newFactory()
    {
        return MovementFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'asset_id',
        'from_location_id',
        'to_location_id',
        'source',
        'recorded_by',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
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

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
