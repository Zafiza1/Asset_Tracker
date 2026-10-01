<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'asset_id',
        'project_id',
        'bound_at',
        'unbound_at',
        'metadata',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'unbound_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('unbound_at');
    }

    public function scopeInactive($query)
    {
        return $query->whereNotNull('unbound_at');
    }

    public function isActive(): bool
    {
        return is_null($this->unbound_at);
    }
}
