<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable release of a module (Section 15). Projects pin a version via
 * ProjectModule::module_version_id.
 */
class ModuleVersion extends Model
{
    protected $fillable = [
        'module_id',
        'version',
        'changelog',
        'dependencies',
        'config_schema',
        'permissions',
        'status',
        'released_at',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'config_schema' => 'array',
        'permissions' => 'array',
        'released_at' => 'datetime',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * @return array<string, string> module slug => version constraint
     */
    public function dependencyMap(): array
    {
        return $this->dependencies ?? [];
    }

    public function dependsOn(string $moduleSlug): bool
    {
        return array_key_exists($moduleSlug, $this->dependencyMap());
    }

    /**
     * @return array<string, array<string, mixed>> setting key => definition
     */
    public function configSchema(): array
    {
        return $this->config_schema ?? [];
    }

    /**
     * Configuration populated with every schema default.
     */
    public function defaultConfiguration(): array
    {
        $defaults = [];

        foreach ($this->configSchema() as $key => $definition) {
            if (array_key_exists('default', $definition)) {
                $defaults[$key] = $definition['default'];
            }
        }

        return $defaults;
    }
}
