<?php

namespace App\Modules\Inspection\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable list of checks for a project's inspections.
 *
 * items: [{key, label, type: pass_fail|number|text, required}]
 */
class InspectionChecklist extends Model
{
    use TenantScoping;

    public const ITEM_TYPES = ['pass_fail', 'number', 'text'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'description',
        'asset_type',
        'items',
        'active',
    ];

    protected $casts = [
        'items' => 'array',
        'active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'checklist_id');
    }
}
