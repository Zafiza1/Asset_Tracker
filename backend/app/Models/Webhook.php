<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Webhook extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'endpoint',
        'secret',
        'events',
        'active',
        'retry_policy',
        'metadata',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'retry_policy' => 'array',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForEvent($query, string $eventType)
    {
        return $query->whereJsonContains('events', $eventType);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject($query, ?int $projectId)
    {
        if ($projectId === null) {
            return $query->whereNull('project_id');
        }

        return $query->where(function ($q) use ($projectId) {
            $q->where('project_id', $projectId)
              ->orWhereNull('project_id');
        });
    }

    public function shouldDeliverEvent(string $eventType): bool
    {
        return $this->active && in_array($eventType, $this->events);
    }

    public function getRetryPolicy(): array
    {
        return $this->retry_policy ?? [
            'max_attempts' => 3,
            'retry_delay' => 60,
            'backoff_multiplier' => 2,
        ];
    }
}
