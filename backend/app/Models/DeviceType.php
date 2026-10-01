<?php

namespace App\Models;

use Database\Factories\DeviceTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceType extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return DeviceTypeFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'capabilities',
        'metadata',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'metadata' => 'array',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function scopeOfSlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }
}
