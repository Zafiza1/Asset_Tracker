<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'version',
        'description',
        'default_modules',
        'default_settings',
        'metadata',
        'status',
        'released_at',
    ];

    protected $casts = [
        'default_modules' => 'array',
        'default_settings' => 'array',
        'metadata' => 'array',
        'released_at' => 'datetime',
    ];

    // Relationships
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'template_modules', 'template_version_id')
            ->withPivot('version_constraint', 'required', 'default_config', 'sort_order')
            ->withTimestamps()
            ->orderBy('template_modules.sort_order');
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeDeprecated($query)
    {
        return $query->where('status', 'deprecated');
    }

    public function scopeReleased($query)
    {
        return $query->whereNotNull('released_at');
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('released_at', 'desc');
    }

    // Helper methods
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null && $this->released_at->isPast();
    }

    public function hasModule(string $moduleSlug): bool
    {
        return $this->modules()->where('slug', $moduleSlug)->exists();
    }

    public function getModuleConfig(string $moduleSlug): ?array
    {
        $module = $this->modules()->where('slug', $moduleSlug)->first();
        return $module ? $module->pivot->default_config : null;
    }
}
