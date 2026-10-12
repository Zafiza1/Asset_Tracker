<?php

namespace App\Domain\TenantMaster\Schema;

/**
 * Deterministic JSON: object keys sorted recursively, list order kept. The same schema
 * always yields the same bytes and therefore the same hash, also after a round-trip
 * through jsonb (which reorders object keys).
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), self::FLAGS);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
