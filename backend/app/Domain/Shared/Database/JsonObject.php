<?php

namespace App\Domain\Shared\Database;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON object column (CHECK jsonb_typeof = 'object') read as an associative array. Unlike the
 * `array` cast, an empty value is stored as {} rather than [].
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|null>
 */
final class JsonObject implements CastsAttributes
{
    /** @return array<string, mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode((object) ($value ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
