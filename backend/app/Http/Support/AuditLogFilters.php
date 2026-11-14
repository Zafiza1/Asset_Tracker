<?php

namespace App\Http\Support;

use App\Domain\Audit\AuditEntityLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class AuditLogFilters
{
    /** Comma-separated action codes or module prefixes, e.g. "asset.created,asset.updated" / "asset,asset_transaction". */
    private const CODE_LIST = '/^[a-z_]+(\.[a-z_]+)?(,[a-z_]+(\.[a-z_]+)?)*$/';

    public static function apply(Builder $query, Request $request): Builder
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            // Activity category: the client turns it into the action codes it covers.
            'actions' => ['nullable', 'string', 'max:4000', 'regex:'.self::CODE_LIST],
            'module' => ['nullable', 'string', 'max:500', 'regex:'.self::CODE_LIST],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'actor_user_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            // Action codes whose readable (Indonesian) label matches the search, so "foto" finds asset.image_updated.
            'search_actions' => ['nullable', 'string', 'max:4000', 'regex:'.self::CODE_LIST],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $query
            ->when($request->query('action'), fn ($q, $v) => str_ends_with($v, '.*')
                ? $q->where('action', 'like', addcslashes(substr($v, 0, -1), '%_\\').'%')
                : $q->where('action', $v))
            ->when($request->query('actions'), fn ($q, $v) => $q->whereIn('action', explode(',', $v)))
            ->when($request->query('module'), fn ($q, $v) => $q->where(function ($q) use ($v) {
                foreach (explode(',', $v) as $prefix) {
                    $q->orWhere('action', 'like', addcslashes($prefix, '%_\\').'.%');
                }
            }))
            ->when($request->query('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->query('entity_id'), fn ($q, $v) => $q->where('entity_id', $v))
            ->when($request->query('actor_user_id'), fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when(trim((string) $request->query('search')), fn ($q, $v) => self::search($q, $v, (string) $request->query('search_actions')))
            // A bare date means the whole day, so "sampai 12 Nov" includes the 12th.
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', self::isDate($v) ? "{$v} 00:00:00" : $v))
            ->when($request->query('to'), fn ($q, $v) => self::isDate($v)
                ? $q->where('created_at', '<', date('Y-m-d', strtotime("{$v} +1 day")))
                : $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** Actor name or email, action, object id/code/name (anything in the recorded snapshots). */
    private static function search(Builder $query, string $term, string $labelMatches): Builder
    {
        $like = ListQuery::like($term);

        return $query->where(function ($q) use ($like, $term, $labelMatches) {
            $q->whereHas('actor', fn ($a) => $a->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))
                ->orWhere('action', 'ilike', $like)
                ->orWhere('entity_id', $term)
                // Internal asset numbers in older snapshots are not shown, so they are not searchable either.
                ->orWhereRaw('("before" - \'asset_number\')::text ilike ?', [$like])
                ->orWhereRaw('("after" - \'asset_number\')::text ilike ?', [$like])
                ->orWhereRaw('"metadata"::text ilike ?', [$like])
                ->orWhere(fn ($e) => AuditEntityLabels::whereMatches($e, $term));
            if ($labelMatches !== '') {
                $q->orWhereIn('action', explode(',', $labelMatches));
            }
        });
    }

    private static function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
