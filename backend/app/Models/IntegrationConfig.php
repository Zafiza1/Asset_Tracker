<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'key',
        'value',
        'is_secret',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function scopeNotSecret($query)
    {
        return $query->where('is_secret', false);
    }

    public function scopeSecret($query)
    {
        return $query->where('is_secret', true);
    }
}
