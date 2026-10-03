<?php

namespace App\Modules\Inspection\Models;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inspection of an asset, scheduled and then recorded against a
 * checklist. Owned by the Inspection module; tenant-scoped.
 */
class Inspection extends Model
{
    use TenantScoping;

    public const STATUSES = ['scheduled', 'completed', 'cancelled'];

    public const RESULTS = ['pass', 'fail'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'asset_id',
        'checklist_id',
        'status',
        'result',
        'scheduled_at',
        'performed_at',
        'next_due_at',
        'checklist_snapshot',
        'answers',
        'notes',
        'metadata',
        'performed_by',
        'created_by',
    ];

    protected $casts = [
        'checklist_snapshot' => 'array',
        'answers' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'performed_at' => 'datetime',
        'next_due_at' => 'datetime',
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

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(InspectionChecklist::class, 'checklist_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
