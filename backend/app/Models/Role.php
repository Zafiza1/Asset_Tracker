<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return RoleFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'level',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    // Relationships
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
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

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', '>=', $level);
    }

    // Helper methods
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions()->where('slug', $permissionSlug)->exists();
    }

    public function givePermission(string|array $permissionSlugs): self
    {
        $permissions = is_array($permissionSlugs) ? $permissionSlugs : [$permissionSlugs];
        
        foreach ($permissions as $slug) {
            $permission = Permission::where('slug', $slug)->first();
            if ($permission) {
                $this->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        return $this;
    }

    public function revokePermission(string|array $permissionSlugs): self
    {
        $permissions = is_array($permissionSlugs) ? $permissionSlugs : [$permissionSlugs];
        
        foreach ($permissions as $slug) {
            $permission = Permission::where('slug', $slug)->first();
            if ($permission) {
                $this->permissions()->detach($permission->id);
            }
        }

        return $this;
    }

    public function syncPermissions(array $permissionSlugs): self
    {
        $permissionIds = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->toArray();
        $this->permissions()->sync($permissionIds);

        return $this;
    }

    public function isSystemRole(): bool
    {
        return $this->is_system;
    }
}
