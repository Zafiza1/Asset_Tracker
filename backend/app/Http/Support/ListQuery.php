<?php

namespace App\Http\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;

/**
 * Safe pagination and sorting: per_page is clamped and sort columns are whitelisted,
 * so request input never reaches SQL identifiers directly.
 */
final class ListQuery
{
    public const MAX_PER_PAGE = 10000;

    public static function perPage(Request $request, int $default = 10): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', (string) $default)));
    }

    /**
     * Order by `?sort=key` (ascending) or `?sort=-key` (descending); an unknown key falls back to
     * `$default`. Empty values come last in both directions, and the primary key breaks ties so
     * rows never repeat or vanish between pages.
     *
     * @param  array<string, string|Expression|EloquentBuilder<Model>|QueryBuilder>  $allowed  public sort key => column or scalar subquery
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

        $column = $allowed[$key];
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        if ($column instanceof EloquentBuilder || $column instanceof QueryBuilder) {
            $sub = $column instanceof EloquentBuilder ? $column->toBase() : $column;
            $query->orderByRaw("({$sub->toSql()}) {$direction} nulls last", $sub->getBindings());
        } else {
            $sql = $column instanceof Expression ? (string) $column->getValue($base->getGrammar()) : $base->getGrammar()->wrap($column);
            $query->orderByRaw("{$sql} {$direction} nulls last");
        }

        if ($query instanceof EloquentBuilder) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        return $query;
    }

    /**
     * Scalar subquery reading `$column` of a belongs-to relation, to sort by e.g. the category
     * name of an asset without joining (and without changing the selected columns).
     *
     * @return EloquentBuilder<Model>
     */
    public static function related(Model $model, string $relation, string $column): EloquentBuilder
    {
        /** @var BelongsTo<Model, Model> $belongsTo */
        $belongsTo = $model->{$relation}();
        $related = $belongsTo->getRelated();
        $alias = 'sort_'.$relation;

        return $related->newQueryWithoutScopes()
            ->from($related->getTable(), $alias)
            ->select("{$alias}.{$column}")
            ->whereColumn("{$alias}.{$belongsTo->getOwnerKeyName()}", $belongsTo->getQualifiedForeignKeyName())
            ->limit(1);
    }

    /** Escape LIKE wildcards in user search input. */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
