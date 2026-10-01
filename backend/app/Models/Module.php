<?php

namespace App\Models;

use Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business module in the platform registry (Section 13). Global, not
 * tenant-owned — see ProjectModule for a module installed in a project.
 */
class Module extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return ModuleFactory::new();
    }

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'author',
        'is_core',
        'status',
        'metadata',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ModuleVersion::class);
    }

    public function projectModules(): HasMany
    {
        return $this->hasMany(ProjectModule::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    /**
     * The highest published version, or null if none is installable.
     */
    public function latestVersion(): ?ModuleVersion
    {
        $versions = $this->relationLoaded('versions')
            ? $this->versions
            : $this->versions()->get();

        return $versions
            ->filter(fn (ModuleVersion $version) => $version->isPublished())
            ->sort(fn (ModuleVersion $a, ModuleVersion $b) => version_compare($b->version, $a->version))
            ->first();
    }

    public function findVersion(string $version): ?ModuleVersion
    {
        return $this->versions()->where('version', $version)->first();
    }
}
