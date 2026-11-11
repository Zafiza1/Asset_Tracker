<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Http\Resources\AssetTransactionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The whole life of one asset in one list, newest first: its transactions (registration,
 * transfers, loans, status changes, deactivation) and the edits to its data that only the
 * audit log records (descriptive fields, category fields, photo, document).
 */
final class AssetTimeline
{
    /** Audit actions that are not already a transaction of the asset. */
    public const EDIT_ACTIONS = ['asset.updated', 'asset.image_updated', 'asset.image_removed', 'asset.document_updated', 'asset.document_removed'];

    /** Upper bound per source; an asset with more events shows the most recent ones. */
    public const LIMIT = 500;

    private const FIELD_LABELS = [
        'name' => 'Nama aset',
        'serial_number' => 'No seri',
        'category_id' => 'Kategori',
        'acquisition_date' => 'Tanggal perolehan',
        'acquisition_cost' => 'Harga perolehan',
        'description' => 'Deskripsi',
    ];

    private const FILE_LABELS = [
        'asset.image_updated' => 'Foto aset diganti',
        'asset.image_removed' => 'Foto aset dihapus',
        'asset.document_updated' => 'Dokumen aset diunggah',
        'asset.document_removed' => 'Dokumen aset dihapus',
    ];

    /** @return list<array<string, mixed>> */
    public function for(Asset $asset, Request $request, bool $withTransactions): array
    {
        $events = $withTransactions ? $this->transactions($asset, $request) : collect();
        $events = $events->concat($this->edits($asset));

        // Transactions carry the date the user stated (possibly backdated), edits only a timestamp:
        // order by day first, then by when each was written.
        return $events->sort(fn ($a, $b) => [$b['_day'], $b['_at']] <=> [$a['_day'], $a['_at']])
            ->map(fn ($e) => array_diff_key($e, ['_day' => 0, '_at' => 0]))
            ->values()->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function transactions(Asset $asset, Request $request): Collection
    {
        return $asset->transactions()->with(['fromLocation', 'toLocation', 'creator'])
            ->orderByDesc('seq')->limit(self::LIMIT)->get()
            ->map(fn ($t) => [
                'id' => 'trx-'.$t->id,
                'kind' => 'transaction',
                'occurred_at' => $t->created_at?->toIso8601String(),
                'actor' => $t->creator?->name,
                'transaction' => (new AssetTransactionResource($t))->toArray($request),
                '_day' => $t->transaction_date->toDateString(),
                '_at' => $t->created_at?->format('Y-m-d H:i:s.u'),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function edits(Asset $asset): Collection
    {
        $logs = AuditLog::query()->forOrganization($asset->organization_id)
            ->where('entity_type', class_basename($asset))->where('entity_id', $asset->id)
            ->whereIn('action', self::EDIT_ACTIONS)
            ->with('actor')->orderByDesc('created_at')->limit(self::LIMIT)->get();

        $names = $this->names($logs);

        return $logs->map(function (AuditLog $log) use ($names) {
            $changes = $log->action === 'asset.updated' ? $this->changes($log->before ?? [], $log->after ?? [], $names) : [];
            if ($log->action === 'asset.updated' && $changes === []) {
                return null;
            }
            /** @var Carbon $at */
            $at = $log->created_at;

            return [
                'id' => 'log-'.$log->id,
                'kind' => $log->action === 'asset.updated' ? 'data_change' : 'file_change',
                'occurred_at' => $at->toIso8601String(),
                'actor' => $log->actor?->name ?? ($log->actor_type === 'system' ? 'Sistem' : null),
                'title' => self::FILE_LABELS[$log->action] ?? 'Data aset diubah',
                'changes' => $changes,
                '_day' => $at->toDateString(),
                '_at' => $at->format('Y-m-d H:i:s.u'),
            ];
        })->filter()->values();
    }

    /**
     * One row per changed field. Category fields are compared key by key.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array{categories: array<string, string>, attributes: array<string, TenantCategoryAttribute>}  $names
     * @return list<array{field: string, label: string, before: mixed, after: mixed}>
     */
    private function changes(array $before, array $after, array $names): array
    {
        $rows = [];
        foreach ($after as $field => $value) {
            if ($field === 'attributes') {
                $old = is_array($before['attributes'] ?? null) ? $before['attributes'] : [];
                $new = is_array($value) ? $value : [];
                foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
                    if (($old[$key] ?? null) == ($new[$key] ?? null)) {
                        continue;
                    }
                    $attribute = $names['attributes'][$key] ?? null;
                    $rows[] = [
                        'field' => "attributes.{$key}",
                        'label' => $attribute->label ?? $key,
                        'before' => self::optionLabel($attribute, $old[$key] ?? null),
                        'after' => self::optionLabel($attribute, $new[$key] ?? null),
                    ];
                }

                continue;
            }
            if (! isset(self::FIELD_LABELS[$field])) {
                continue;
            }
            $old = $before[$field] ?? null;
            if ($field === 'category_id') {
                [$old, $value] = [$names['categories'][$old] ?? $old, $names['categories'][$value] ?? $value];
            }
            $rows[] = ['field' => $field, 'label' => self::FIELD_LABELS[$field], 'before' => $old, 'after' => $value];
        }

        return $rows;
    }

    /**
     * Category names and attribute definitions mentioned by the logs, in two queries.
     * Archived attributes are included: their keys are never reused.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array{categories: array<string, string>, attributes: array<string, TenantCategoryAttribute>}
     */
    private function names(Collection $logs): array
    {
        $categoryIds = [];
        $keys = [];
        foreach ($logs as $log) {
            foreach ([$log->before ?? [], $log->after ?? []] as $side) {
                if (is_string($side['category_id'] ?? null)) {
                    $categoryIds[] = $side['category_id'];
                }
                if (is_array($side['attributes'] ?? null)) {
                    array_push($keys, ...array_map('strval', array_keys($side['attributes'])));
                }
            }
        }

        return [
            'categories' => $categoryIds === [] ? [] : TenantAssetCategory::query()->whereIn('id', array_unique($categoryIds))->pluck('name', 'id')->all(),
            'attributes' => $keys === [] ? [] : TenantCategoryAttribute::query()->whereIn('key', array_unique($keys))->get()->keyBy('key')->all(),
        ];
    }

    private static function optionLabel(?TenantCategoryAttribute $attribute, mixed $value): mixed
    {
        if ($attribute === null || ! is_array($attribute->options) || $value === null) {
            return $value;
        }
        $labels = array_column($attribute->options, 'label', 'value');
        if (is_array($value)) {
            return array_map(fn ($v) => is_scalar($v) ? ($labels[$v] ?? $v) : $v, $value);
        }

        return is_scalar($value) ? ($labels[$value] ?? $value) : $value;
    }
}
