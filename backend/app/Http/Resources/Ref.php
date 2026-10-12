<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/** Compact `{id, code, name}` reference to a related master record. */
final class Ref
{
    /** @return array{id: string, code: string, name: string}|null */
    public static function of(?Model $model): ?array
    {
        return $model === null ? null : [
            'id' => $model->getKey(),
            'code' => $model->getAttribute('code'),
            'name' => $model->getAttribute('name'),
        ];
    }

    /** Ancestors in a materialized path ("/<root>/.../<id>/"). */
    public static function depth(string $path): int
    {
        return max(0, substr_count($path, '/') - 2);
    }
}
