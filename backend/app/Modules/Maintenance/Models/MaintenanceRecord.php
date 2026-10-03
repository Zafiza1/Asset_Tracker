<?php

namespace App\Modules\Maintenance\Models;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled or performed maintenance job on an asset. Owned by the
 * Maintenance module; tenant-scoped like every project resource.
 */
class MaintenanceRecord extends Model
{
    use TenantScoping;

    public const STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    /** Statuses a record can no longer leave. */
    public const FINAL_STATUSES = ['completed', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'asset_id',
        'title',
        'description',
        'type',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'notes',
        'cost',
        'metadata',
        'previous_record_id',
        'created_by',
        'completed_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'cost' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function previousRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }
}
