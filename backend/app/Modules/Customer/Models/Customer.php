<?php

namespace App\Modules\Customer\Models;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer or other party that holds or receives assets. Owned by the
 * Customer module; tenant-scoped like every project resource.
 */
class Customer extends Model
{
    use SoftDeletes, TenantScoping;

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'organization_id',
        'project_id',
        'code',
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'location_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The customer's site, where delivered assets end up. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
