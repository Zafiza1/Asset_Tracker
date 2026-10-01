<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_version_id',
        'module_id',
        'version_constraint',
        'required',
        'default_config',
        'sort_order',
    ];

    protected $casts = [
        'required' => 'boolean',
        'default_config' => 'array',
    ];

    // Relationships
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_version_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    // Scopes
    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function scopeOptional($query)
    {
        return $query->where('required', false);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // Helper methods
    public function isRequired(): bool
    {
        return $this->required === true;
    }

    public function satisfiesVersion(string $moduleVersion): bool
    {
        if (!$this->version_constraint) {
            return true;
        }

        // Use VersionConstraint support to check if module version satisfies constraint
        return VersionConstraint::satisfies($moduleVersion, $this->version_constraint);
    }
}
