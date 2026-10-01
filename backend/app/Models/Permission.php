<?php

namespace App\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return PermissionFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'module',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    // Relationships
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')
                    ->withPivot('organization_id', 'project_id')
                    ->withTimestamps();
    }

    // Scopes
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    // Helper methods
    public function isSystemPermission(): bool
    {
        return $this->is_system;
    }
}
