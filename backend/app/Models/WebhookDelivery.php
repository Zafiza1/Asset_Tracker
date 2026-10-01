<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;
    protected $fillable = [
        'webhook_id',
        'event_log_id',
        'event_type',
        'payload',
        'status',
        'attempt',
        'max_attempts',
        'attempt_at',
        'delivered_at',
        'response_body',
        'response_status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'response_status' => 'integer',
        'metadata' => 'array',
        'attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function eventLog(): BelongsTo
    {
        return $this->belongsTo(EventLog::class, 'event_log_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRetrying($query)
    {
        return $query->where('status', 'retrying');
    }

    public function scopeShouldRetry($query)
    {
        return $query->whereIn('status', ['pending', 'retrying'])
            ->whereColumn('attempt', '<', 'max_attempts');
    }

    public function markAsDelivered(?string $responseBody = null, ?int $responseStatus = null): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'response_body' => $responseBody,
            'response_status' => $responseStatus,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function markForRetry(): void
    {
        $this->increment('attempt');
        $this->update([
            'status' => 'retrying',
            'attempt_at' => now(),
        ]);
    }

    public function canRetry(): bool
    {
        return $this->attempt < $this->max_attempts && in_array($this->status, ['pending', 'retrying']);
    }

    public function calculateNextRetryDelay(): int
    {
        $policy = $this->webhook->getRetryPolicy();
        $baseDelay = $policy['retry_delay'] ?? 60;
        $multiplier = $policy['backoff_multiplier'] ?? 2;

        return (int) ($baseDelay * pow($multiplier, $this->attempt - 1));
    }
}
