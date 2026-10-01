<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module installed in a project, pinned to one ModuleVersion (Section 14/15).
 * State changes go through App\Services\ModuleService, never direct updates,
 * so lifecycle rules, dependency checks and hooks always run.
 */
class ProjectModule extends Model
{
    use TenantScoping;

    public const STATUS_INSTALLED = 'installed';
    public const STATUS_CONFIGURED = 'configured';
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_UNINSTALLED = 'uninstalled';

    protected $fillable = [
        'organization_id',
        'project_id',
        'module_id',
        'module_version_id',
        'status',
        'configuration',
        'installed_by',
        'installed_at',
        'enabled_at',
        'disabled_at',
        'uninstalled_at',
    ];

    protected $casts = [
        'configuration' => 'array',
        'installed_at' => 'datetime',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function moduleVersion(): BelongsTo
    {
        return $this->belongsTo(ModuleVersion::class);
    }

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * Installed in any state short of uninstalled.
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_UNINSTALLED);
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function isInstalled(): bool
    {
        return $this->status !== self::STATUS_UNINSTALLED;
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }
}
