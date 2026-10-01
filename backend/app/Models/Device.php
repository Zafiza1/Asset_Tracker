<?php

namespace App\Models;

use App\Traits\TenantScoping;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory, SoftDeletes, TenantScoping;

    protected static function newFactory()
    {
        return DeviceFactory::new();
    }

    protected $fillable = [
        'organization_id',
        'project_id',
        'device_type_id',
        'integration_id',
        'serial_number',
        'name',
        'status',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->system_id)) {
                $device->system_id = 'DEV-' . strtoupper((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'system_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deviceType(): BelongsTo
    {
        return $this->belongsTo(DeviceType::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(DeviceBinding::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(EventLog::class)->orderByDesc('occurred_at');
    }

    public function currentBinding(): HasMany
    {
        return $this->hasMany(DeviceBinding::class)
            ->whereNull('unbound_at')
            ->latest('bound_at');
    }

    public function scopeWithAsset($query)
    {
        return $query->with(['currentBinding.asset']);
    }

    public function boundAsset(): ?Asset
    {
        $binding = $this->currentBinding()->first();
        return $binding?->asset;
    }

    public function scopeOfType($query, string $type)
    {
        return $query->whereHas('deviceType', fn($q) => $q->where('slug', $type));
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function isBound(): bool
    {
        return $this->currentBinding()->exists();
    }
}
