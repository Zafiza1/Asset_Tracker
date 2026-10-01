<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return IntegrationFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'type',
        'provider',
        'status',
        'metadata',
        'last_connected_at',
        'last_health_check_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_connected_at' => 'datetime',
        'last_health_check_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(IntegrationConfig::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class)->orderByDesc('occurred_at');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }
}
