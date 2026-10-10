<?php

namespace App\Http\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class AuditLogFilters
{
    public static function apply(Builder $query, Request $request): Builder
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'actor_user_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $query
            ->when($request->query('action'), fn ($q, $v) => str_ends_with($v, '.*')
                ? $q->where('action', 'like', addcslashes(substr($v, 0, -1), '%_\\').'%')
                : $q->where('action', $v))
            ->when($request->query('entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->query('entity_id'), fn ($q, $v) => $q->where('entity_id', $v))
            ->when($request->query('actor_user_id'), fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
