<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return TemplateFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'version',
        'status',
        'default_modules',
        'default_settings',
        'metadata',
        'current_version_id',
    ];

    protected $casts = [
        'default_modules' => 'array',
        'default_settings' => 'array',
        'metadata' => 'array',
    ];

    // Relationships
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'template_modules', 'template_version_id')
            ->withPivot('version_constraint', 'required', 'default_config', 'sort_order')
            ->withTimestamps()
            ->orderBy('template_modules.sort_order');
    }

    // Scoping
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeDeprecated($query)
    {
        return $query->where('status', 'deprecated');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // Helper methods
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function hasModule(string $moduleSlug): bool
    {
        // Check in current version's modules first
        if ($this->currentVersion && $this->currentVersion->hasModule($moduleSlug)) {
            return true;
        }

        // Fallback to legacy default_modules JSON field
        return in_array($moduleSlug, $this->default_modules ?? []);
    }

    public function getModulesForCurrentVersion(): BelongsToMany
    {
        if ($this->currentVersion) {
            return $this->currentVersion->modules();
        }

        return $this->modules();
    }

    public function getCurrentVersion(): ?TemplateVersion
    {
        return $this->currentVersion ?? $this->versions()->latest()->first();
    }
}
