<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return OrganizationFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'email',
        'phone',
        'address',
        'logo',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    // Relationships
    public function users()
    {
        return $this->belongsToMany(User::class, 'organization_users')
                    ->withPivot('role', 'permissions', 'joined_at')
                    ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    // Scoping
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    // Helper methods
    public function hasUser(int $userId): bool
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    public function getUserRole(int $userId): ?string
    {
        $userOrg = $this->users()->where('user_id', $userId)->first();
        return $userOrg ? $userOrg->pivot->role : null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
