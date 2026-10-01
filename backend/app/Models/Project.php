<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Traits\TenantScoping;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return ProjectFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'template_id',
        'name',
        'slug',
        'description',
        'status',
        'settings',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
                    ->withPivot('role', 'permissions', 'joined_at')
                    ->withTimestamps();
    }

    public function projectModules(): HasMany
    {
        return $this->hasMany(ProjectModule::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * Whether the module can be used in this project right now: core modules
     * always, anything else only once installed and enabled (Section 14).
     */
    public function hasModuleEnabled(string $moduleSlug): bool
    {
        $module = Module::where('slug', $moduleSlug)->first();

        if (!$module) {
            return false;
        }

        if ($module->is_core) {
            return true;
        }

        // Already constrained to this project; bypass the request-context
        // scope so the answer is the same inside jobs or another context.
        return $this->projectModules()
            ->withoutGlobalScope(TenantScope::class)
            ->where('module_id', $module->id)
            ->enabled()
            ->exists();
    }

    // Tenant Scoping
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where('id', $projectId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    // Helper methods
    public function hasUser(int $userId): bool
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    public function getUserRole(int $userId): ?string
    {
        $userProject = $this->users()->where('user_id', $userId)->first();
        return $userProject ? $userProject->pivot->role : null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOwnedByOrganization(int $organizationId): bool
    {
        return $this->organization_id === $organizationId;
    }
}
