<?php

namespace App\Domain\Shared\Tenancy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Signs the database context claim that Row-Level Security trusts (SEC-07).
 *
 * Canonical form, verified by ate_ctx.verified_context() in PostgreSQL:
 *
 *     v1|<kid>|<backend pid>|<backend start, µs since epoch>|<expires, s since epoch>|<mode>|<org>|<user>.<hex HMAC-SHA256>
 *
 * Every field has a fixed alphabet (decimal digits without leading zeros, a mode from a fixed
 * list, lowercase UUIDs or empty), none of which can contain the delimiters, so one payload has
 * exactly one parse. The claim is bound to one database backend (pid + start time), so it is
 * useless on any other connection, and it expires.
 */
final class ContextClaim
{
    public const MODES = ['tenant', 'platform', 'system'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        private readonly string $key,
        private readonly int $kid,
        public readonly int $ttl,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('database.context');
        $key = self::decodeKey($config['key'] ?? null);
        $kid = (int) ($config['kid'] ?? 0);
        $ttl = (int) ($config['ttl'] ?? 0);
        if ($kid < 1 || $kid > 32767) {
            throw new RuntimeException('DB_CONTEXT_KID must be an integer between 1 and 32767.');
        }
        if ($ttl < 60 || $ttl > 86400) {
            throw new RuntimeException('DB_CONTEXT_TTL must be between 60 and 86400 seconds.');
        }

        return new self($key, $kid, $ttl);
    }

    /** The raw 32-byte key from "base64:<...>" (the APP_KEY convention). */
    public static function decodeKey(?string $value): string
    {
        if ($value === null || $value === '') {
            throw new RuntimeException('DB_CONTEXT_KEY is not configured.');
        }
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('DB_CONTEXT_KEY must be "base64:" followed by 32 random bytes.');
        }

        return $raw;
    }

    public function kid(): int
    {
        return $this->kid;
    }

    public function sign(string $mode, ?string $organizationId, ?string $userId, int $pid, int $backendStartMicros, int $now): string
    {
        $organizationId ??= '';
        $userId ??= '';
        $valid = in_array($mode, self::MODES, true)
            && $pid > 0 && $backendStartMicros > 0
            && match ($mode) {
                'tenant' => self::isUuid($organizationId) && self::isUuid($userId),
                'platform' => $organizationId === '' && self::isUuid($userId),
                'system' => $organizationId === '' && $userId === '',
            };
        if (! $valid) {
            throw new InvalidArgumentException('Invalid database context.');
        }

        $payload = implode('|', ['v1', $this->kid, $pid, $backendStartMicros, $now + $this->ttl, $mode, $organizationId, $userId]);

        return $payload.'.'.hash_hmac('sha256', $payload, $this->key);
    }

    /**
     * HMAC-SHA256 pads for the database verifier, which computes sha256(outer || sha256(inner || m))
     * with built-in functions only (no pgcrypto). They are as secret as the key itself.
     *
     * @return array{inner: string, outer: string}
     */
    public static function pads(string $rawKey): array
    {
        $block = str_pad($rawKey, 64, "\0");

        return ['inner' => $block ^ str_repeat("\x36", 64), 'outer' => $block ^ str_repeat("\x5c", 64)];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }
}
