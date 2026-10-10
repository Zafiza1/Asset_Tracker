<?php

namespace App\Http\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Safe pagination and sorting: per_page is clamped and sort columns are whitelisted,
 * so request input never reaches SQL identifiers directly.
 */
final class ListQuery
{
    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', (string) $default)));
    }

    /**
     * @param  array<string, string>  $allowed  public sort key => column
     */
    public static function sort(Builder $query, Request $request, array $allowed, string $default): Builder
    {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! isset($allowed[$key])) {
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
            $key = ltrim($default, '-');
        }

        return $query->orderBy($allowed[$key], $direction);
    }

    /** Escape LIKE wildcards in user search input. */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
