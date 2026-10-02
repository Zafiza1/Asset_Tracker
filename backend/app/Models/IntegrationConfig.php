<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * One key/value setting of an integration. Secret values (API keys, tokens,
 * passwords) are encrypted at rest with the application key and must never
 * be returned to clients — see IntegrationResource.
 */
class IntegrationConfig extends Model
{
    use HasFactory;

    /** Marks a stored value as encrypted (legacy rows may be plaintext). */
    public const ENCRYPTED_PREFIX = 'enc:';

    public const MASK = '********';

    protected $fillable = [
        'integration_id',
        'key',
        'value',
        'is_secret',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Encrypt on save rather than in the mutator: is_secret may be
        // assigned after value in the same fill().
        static::saving(function (self $config) {
            $raw = $config->getAttributes()['value'] ?? null;

            if ($config->is_secret && is_string($raw) && $raw !== '' && !str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
                $config->attributes['value'] = self::ENCRYPTED_PREFIX . Crypt::encryptString($raw);
            }
        });
    }

    protected function value(): Attribute
    {
        return Attribute::get(function (?string $raw) {
            if ($raw !== null && str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
                return Crypt::decryptString(substr($raw, strlen(self::ENCRYPTED_PREFIX)));
            }

            return $raw;
        });
    }

    /**
     * Value safe to show to a client.
     */
    public function displayValue(): ?string
    {
        return $this->is_secret ? self::MASK : $this->value;
    }

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
