<?php

namespace App\Domain\Dashboards\Widgets\Providers;

use App\Domain\Assets\AssetDataQuality;
use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Dashboards\Widgets\WidgetContext;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Providers for the asset widgets of the catalog (F-06 §4.3): KPI numbers, distributions,
 * trends and asset lists. Every query goes through WidgetContext, so the member's data scope
 * and the widget's filters apply identically everywhere.
 */
final class AssetWidgets
{
    /** Age buckets by acquisition date: key => [from years, to years|null]. */
    private const AGE_BUCKETS = [
        'lt1' => [0, 1, '< 1 tahun'],
        '1_3' => [1, 3, '1–3 tahun'],
        '3_5' => [3, 5, '3–5 tahun'],
        '5_10' => [5, 10, '5–10 tahun'],
        'gte10' => [10, null, '≥ 10 tahun'],
    ];

    private const CONDITION_VALUES = ['BARU' => 'Baru', 'BAIK' => 'Baik', 'CUKUP' => 'Cukup', 'RUSAK_RINGAN' => 'Rusak Ringan', 'RUSAK_BERAT' => 'Rusak Berat'];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly Tenancy $tenancy,
    ) {}

    // ------------------------------------------------------------------ KPI

    /** @return array<string, mixed> */
    public function totalAssets(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets(includeArchived: (bool) $ctx->config('include_archived'))->count());
    }

    /** Assets in service: in use, on loan, or temporarily outside (the "active inventory"). */
    public function activeAssets(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets()->whereIn('status', [AssetStatus::IN_USE, AssetStatus::ON_LOAN, AssetStatus::OFF_SITE])->count());
    }

    /** Operable assets not in service: available, under maintenance, lost, or disposed. */
    public function inactiveAssets(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets()->whereIn('status', [AssetStatus::AVAILABLE, AssetStatus::UNDER_MAINTENANCE, AssetStatus::LOST, AssetStatus::DISPOSED])->count());
    }

    /** @return array<string, mixed> */
    public function totalValue(WidgetContext $ctx): array
    {
        $value = (string) $ctx->assets()->whereNotNull('acquisition_cost')->sum('acquisition_cost');

        return ['value' => $value, 'unit' => 'money', 'currency' => $this->tenancy->tenant()->organization->currency];
    }

    /** @return array<string, mixed> */
    public function assetsOnLoan(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets()->where('status', AssetStatus::ON_LOAN)->count());
    }

    /** @return array<string, mixed> */
    public function assetsOffSite(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets()->where('status', AssetStatus::OFF_SITE)->count());
    }

    /** @return array<string, mixed> */
    public function assetsUnderMaintenance(WidgetContext $ctx): array
    {
        return $this->stat($ctx->assets()->where('status', AssetStatus::UNDER_MAINTENANCE)->count());
    }

    /** Lost assets plus assets whose condition attribute is a damaged option. */
    public function assetsLostDamaged(WidgetContext $ctx): array
    {
        $damaged = $ctx->assets()->where('status', '!=', AssetStatus::LOST)
            ->whereExists($this->conditionValuesQuery(['RUSAK_RINGAN', 'RUSAK_BERAT']));

        return $this->stat($ctx->assets()->where('status', AssetStatus::LOST)->count() + $damaged->count());
    }

    /**
     * Share of filled data slots: the five optional asset fields (AssetDataQuality) plus
     * "no missing required category attribute".
     *
     * @return array<string, mixed>
     */
    public function dataCompleteness(WidgetContext $ctx): array
    {
        $fields = array_keys(AssetDataQuality::FIELDS);
        $select = ['count(*) AS total'];
        foreach ($fields as $column) {
            $select[] = 'count(*) FILTER (WHERE '.AssetDataQuality::filledCondition($column).") AS {$column}";
        }
        $required = DB::table('tenant_category_attributes as a')
            ->whereColumn('a.category_id', 'assets.category_id')->where('a.is_required', true)
            ->whereRaw("coalesce(assets.attribute_values ->> a.key, '') = ''");
        $select[] = 'count(*) FILTER (WHERE NOT EXISTS ('.$required->toSql().')) AS required_ok';
        $row = $ctx->assets()->toBase()->selectRaw(implode(', ', $select), $required->getBindings())->first();

        $total = (int) $row->total;
        $slots = $total * (count($fields) + 1);
        $filledCount = array_sum(array_map(fn ($c) => (int) $row->{$c}, $fields)) + (int) $row->required_ok;

        return ['value' => $slots > 0 ? round($filledCount / $slots * 100, 1) : null, 'unit' => 'percent'];
    }

    // ------------------------------------------------------- Distribusi (chart/status/location)

    /** @return array<string, mixed> */
    public function assetsByCategory(WidgetContext $ctx): array
    {
        $rows = $ctx->assets()->toBase()
            ->leftJoin('tenant_asset_categories as c', 'c.id', '=', 'assets.category_id')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('count')->orderBy('c.name')
            ->limit($this->limit($ctx, 8))
            ->get(['c.id', 'c.name', DB::raw('count(*) AS count')]);

        return $this->chart($ctx, $rows->map(fn ($r) => ['label' => $r->name ?? 'Tanpa kategori', 'value' => (int) $r->count])->all(), 'count');
    }

    /** @return array<string, mixed> */
    public function assetsByCondition(WidgetContext $ctx): array
    {
        $rows = $this->attributeValues($ctx, CategoryAttributeService::CONDITION_LABEL, ['enum'], 10);

        return $this->chart($ctx, $rows, 'count');
    }

    /** @return array<string, mixed> */
    public function assetsByStatus(WidgetContext $ctx): array
    {
        $counts = $ctx->assets()->toBase()->select('status', DB::raw('count(*) AS count'))->groupBy('status')->pluck('count', 'status');

        return ['items' => array_map(fn (string $s) => [
            'status' => $s, 'label' => AssetStatus::LABELS[$s], 'count' => (int) ($counts[$s] ?? 0),
        ], AssetStatus::ALL)];
    }

    /** @return array<string, mixed> */
    public function assetsByLocation(WidgetContext $ctx): array
    {
        return ['items' => $this->byLocation($ctx, $this->limit($ctx, 8))];
    }

    /** @return array<string, mixed> */
    public function valueByCategory(WidgetContext $ctx): array
    {
        $rows = $ctx->assets()->whereNotNull('acquisition_cost')->toBase()
            ->join('tenant_asset_categories as c', 'c.id', '=', 'assets.category_id')
            ->groupBy('c.id', 'c.name')
            ->havingRaw('sum(assets.acquisition_cost) > 0')
            ->orderByDesc('value')->orderBy('c.name')
            ->limit($this->limit($ctx, 8))
            ->get(['c.id', 'c.name', DB::raw('sum(assets.acquisition_cost) AS value')]);

        return $this->chart($ctx, $rows->map(fn ($r) => ['label' => $r->name, 'value' => (float) $r->value])->all(), 'value');
    }

    /** @return array<string, mixed> */
    public function valueByLocation(WidgetContext $ctx): array
    {
        $rows = $ctx->assets()->whereNotNull('acquisition_cost')->toBase()
            ->leftJoin('locations as l', 'l.id', '=', 'assets.location_id')
            ->groupBy('l.id', 'l.name')
            ->havingRaw('sum(assets.acquisition_cost) > 0')
            ->orderByDesc('value')->orderBy('l.name')
            ->limit($this->limit($ctx, 8))
            ->get(['l.id', 'l.name', DB::raw('sum(assets.acquisition_cost) AS value')]);

        return $this->chart($ctx, $rows->map(fn ($r) => ['label' => $r->name ?? 'Tanpa lokasi', 'value' => (float) $r->value])->all(), 'value');
    }

    /** @return array<string, mixed> */
    public function assetAge(WidgetContext $ctx): array
    {
        $date = $ctx->today()->toDateString();
        $years = $ctx->assets()->where('status', '!=', AssetStatus::DISPOSED)->toBase()
            ->whereNotNull('acquisition_date')
            ->selectRaw("greatest(0, date_part('year', age(cast(? AS date), acquisition_date)))::int AS years", [$date])
            ->selectRaw('count(*) AS count')->groupBy('years')->pluck('count', 'years');

        $items = [];
        foreach (self::AGE_BUCKETS as [$from, $to, $label]) {
            $items[] = ['label' => $label, 'value' => (int) $years->filter(fn ($c, $y) => $y >= $from && ($to === null || $y < $to))->sum()];
        }

        return $this->chart($ctx, $items, 'count');
    }

    /** Count and acquisition value per acquisition year, oldest first. */
    public function assetsByYear(WidgetContext $ctx): array
    {
        $rows = $ctx->assets()->whereNotNull('acquisition_date')->toBase()
            ->selectRaw("to_char(acquisition_date, 'YYYY') AS year")
            ->selectRaw('count(*) AS count')
            ->selectRaw('coalesce(sum(acquisition_cost), 0) AS value')
            ->groupBy('year')->orderBy('year')
            ->limit($this->limit($ctx, 10))
            ->get();

        return [
            'chart' => $ctx->config('visualization') ?? 'bar',
            'categories' => $rows->pluck('year')->all(),
            'series' => [
                ['key' => 'count', 'label' => 'Jumlah', 'data' => $rows->map(fn ($r) => (int) $r->count)->all()],
                ['key' => 'value', 'label' => 'Nilai perolehan', 'data' => $rows->map(fn ($r) => (float) $r->value)->all()],
            ],
        ];
    }

    /** Count of assets per value of one category attribute (choice, text, …). */
    public function attributeDistribution(WidgetContext $ctx): array
    {
        return $this->chart($ctx, $this->attributeValues($ctx, null, ['enum', 'string', 'text'], $this->limit($ctx, 10)), 'count');
    }

    /** Date attributes of each asset's category falling due within `days_ahead` days. */
    public function dateReminders(WidgetContext $ctx): array
    {
        $spec = $ctx->config('attribute') ?? $ctx->config('attribute_label');
        $days = $ctx->intConfig('days_ahead') ?: 30;
        $limit = $ctx->intConfig('limit') ?: 10;
        if ($spec === null) {
            return ['days' => $days, 'count' => 0, 'items' => []];
        }

        $today = $ctx->today();
        $to = $today->addDays($days)->toDateString();
        $value = "CASE WHEN assets.attribute_values ->> a.key ~ '^\\d{4}-\\d{2}-\\d{2}$' THEN (assets.attribute_values ->> a.key)::date END";
        $query = $ctx->assets()->toBase()
            ->join('tenant_category_attributes as a', function ($join) use ($spec) {
                $join->on('a.category_id', '=', 'assets.category_id')->where('a.status', 'active')
                    ->where('a.data_type', 'date')
                    ->where(fn ($w) => $w->where('a.key', $spec)->orWhereRaw('lower(a.label) = ?', [mb_strtolower((string) $spec)]));
            })
            ->whereRaw("{$value} BETWEEN ?::date AND ?::date", [$today->toDateString(), $to]);

        $count = (clone $query)->count();
        $rows = $query->selectRaw("assets.id, assets.name, a.label, {$value} AS due_date")
            ->orderBy('due_date')->limit($limit)->get();

        return [
            'days' => $days,
            'count' => $count,
            'items' => $rows->map(fn ($r) => [
                'asset' => ['id' => $r->id, 'name' => $r->name],
                'label' => $r->label,
                'due_date' => substr((string) $r->due_date, 0, 10),
            ])->all(),
        ];
    }

    /** count / average / min / max of one numeric category attribute. */
    public function attributeSummary(WidgetContext $ctx): array
    {
        $numeric = "CASE WHEN assets.attribute_values ->> a.key ~ '^-?\\d+(\\.\\d+)?$' THEN (assets.attribute_values ->> a.key)::numeric END";
        $query = $this->attributeBase($ctx, ['integer', 'decimal']);
        if ($query === null) {
            return ['columns' => [], 'rows' => [], 'count' => 0];
        }
        $row = $query->selectRaw("count({$numeric}) AS n, avg({$numeric}) AS avg, min({$numeric}) AS min, max({$numeric}) AS max")->first();

        return [
            'columns' => [
                ['key' => 'metric', 'label' => 'Ukuran'],
                ['key' => 'value', 'label' => 'Nilai'],
            ],
            'rows' => [
                ['metric' => 'Jumlah isian', 'value' => (int) $row->n],
                ['metric' => 'Rata-rata', 'value' => $row->avg === null ? null : round((float) $row->avg, 2)],
                ['metric' => 'Minimum', 'value' => $row->min === null ? null : (float) $row->min],
                ['metric' => 'Maksimum', 'value' => $row->max === null ? null : (float) $row->max],
            ],
            'count' => (int) $row->n,
        ];
    }

    // ------------------------------------------------------------------ Tren

    /** Assets registered per month (by acquisition of the registration transaction). */
    public function assetsAddedMonthly(WidgetContext $ctx): array
    {
        return $this->monthly($ctx, [TransactionType::REGISTRATION], $ctx->intConfig('months') ?: 12);
    }

    /** @return array<string, mixed> */
    public function cumulativeValue(WidgetContext $ctx): array
    {
        $months = $ctx->intConfig('months') ?: 12;
        $start = $ctx->monthsStart($months);
        // Value acquired before the window still counts: the series is cumulative.
        $prior = (float) $ctx->assets()->whereNotNull('acquisition_date')
            ->where('acquisition_date', '<', $start->toDateString())
            ->sum('acquisition_cost');
        $rows = $ctx->assets()->whereNotNull('acquisition_date')->toBase()
            ->where('acquisition_date', '>=', $start->toDateString())
            ->selectRaw("to_char(acquisition_date, 'YYYY-MM') AS month")
            ->selectRaw('coalesce(sum(acquisition_cost), 0) AS value')
            ->groupBy('month')->pluck('value', 'month');

        $categories = [];
        $data = [];
        $sum = $prior;
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i)->format('Y-m');
            $categories[] = $month;
            $sum += (float) ($rows[$month] ?? 0);
            $data[] = round($sum, 2);
        }

        return ['chart' => $ctx->config('visualization') ?? 'line', 'categories' => $categories,
            'series' => [['key' => 'value', 'label' => 'Nilai perolehan kumulatif', 'data' => $data]]];
    }

    // ------------------------------------------------------------------ Daftar

    /** @return array<string, mixed> */
    public function recentAssets(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $rows = $ctx->assets()->with(['category:id,name', 'location:id,name'])
            ->orderByDesc('created_at')->limit($limit)->get();

        return [
            'columns' => [
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'category', 'label' => 'Kategori'],
                ['key' => 'location', 'label' => 'Lokasi'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows->map(fn (Asset $a) => [
                'asset' => ['id' => $a->id, 'name' => $a->name, 'asset_number' => $a->asset_number],
                'category' => $a->category?->name,
                'location' => $a->location?->name,
                'status' => ['value' => $a->status, 'label' => AssetStatus::LABELS[$a->status] ?? $a->status],
            ])->all(),
            'count' => $ctx->assets()->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function topLocations(WidgetContext $ctx): array
    {
        return ['items' => $this->byLocation($ctx, $ctx->intConfig('limit') ?: 8)];
    }

    /** @return array<string, mixed> */
    public function incompleteAssetsList(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $missing = DB::table('tenant_category_attributes as a')
            ->whereColumn('a.category_id', 'assets.category_id')->where('a.is_required', true)
            ->whereRaw("coalesce(assets.attribute_values ->> a.key, '') = ''");

        $query = fn () => $ctx->assets()->where(fn ($q) => $q
            ->where(fn ($w) => $w->whereNull('serial_number')->orWhere('serial_number', ''))
            ->orWhereExists($missing)
            ->orWhere(fn ($w) => $w->whereNull('location_id')->where('status', '!=', AssetStatus::DISPOSED)));

        $assets = $query()->with(['category:id,name', 'location:id,name'])->orderBy('asset_number')->limit($limit)->get();

        return [
            'columns' => [
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'missing', 'label' => 'Yang belum diisi'],
            ],
            'rows' => $assets->map(function (Asset $a) {
                $missingFields = [];
                if ($a->serial_number === null || trim((string) $a->serial_number) === '') {
                    $missingFields[] = 'No seri';
                }
                if ($a->location_id === null && $a->status !== AssetStatus::DISPOSED) {
                    $missingFields[] = 'Lokasi';
                }
                $values = $a->attribute_values ?? [];
                foreach (DB::table('tenant_category_attributes')->where('category_id', $a->category_id)
                    ->where('is_required', true)->pluck('label', 'key') as $key => $label) {
                    if (($values[$key] ?? '') === '') {
                        $missingFields[] = $label;
                    }
                }

                return ['asset' => ['id' => $a->id, 'name' => $a->name, 'asset_number' => $a->asset_number], 'missing' => $missingFields];
            })->all(),
            'count' => $query()->count(),
        ];
    }

    /** Available assets without any transaction for `days` days or more. */
    public function idleAssetsList(WidgetContext $ctx): array
    {
        $days = $ctx->intConfig('days') ?: 90;
        $limit = $ctx->intConfig('limit') ?: 10;
        $today = $ctx->today();
        $activity = AssetTransaction::query()->toBase()
            ->select('asset_id', DB::raw('max(transaction_date) AS last_activity'))->groupBy('asset_id');

        $rows = $ctx->assets()->where('status', AssetStatus::AVAILABLE)
            ->joinSub($activity, 'act', 'act.asset_id', '=', 'assets.id')
            ->where('act.last_activity', '<=', $today->subDays($days)->toDateString())
            ->with('location:id,name')->orderBy('act.last_activity')->limit($limit)->get(['assets.*', 'act.last_activity']);

        $count = $ctx->assets()->where('status', AssetStatus::AVAILABLE)
            ->joinSub($activity, 'act', 'act.asset_id', '=', 'assets.id')
            ->where('act.last_activity', '<=', $today->subDays($days)->toDateString())->count();

        return [
            'columns' => [
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'location', 'label' => 'Lokasi'],
                ['key' => 'last_activity', 'label' => 'Aktivitas terakhir'],
                ['key' => 'idle_days', 'label' => 'Hari menganggur'],
            ],
            'rows' => $rows->map(fn (Asset $a) => [
                'asset' => ['id' => $a->id, 'name' => $a->name, 'asset_number' => $a->asset_number],
                'location' => $a->location?->name,
                'last_activity' => CarbonImmutable::parse($a->getAttribute('last_activity'))->toDateString(),
                'idle_days' => (int) CarbonImmutable::parse($a->getAttribute('last_activity'))->diffInDays($today),
            ])->all(),
            'count' => $count,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{value: int|float|string|null, unit: string, currency?: string} */
    private function stat(int|float|string|null $value): array
    {
        return ['value' => $value, 'unit' => 'count'];
    }

    private function limit(WidgetContext $ctx, int $default): int
    {
        return max(1, min(50, $ctx->intConfig('limit') ?: $default));
    }

    /** @return list<array{id: string|null, name: string, count: int}> */
    private function byLocation(WidgetContext $ctx, int $limit): array
    {
        return $ctx->assets()->toBase()
            ->leftJoin('locations as l', 'l.id', '=', 'assets.location_id')
            ->groupBy('l.id', 'l.name')
            ->orderByDesc('count')->orderBy('l.name')
            ->limit($limit)
            ->get(['l.id', 'l.name', DB::raw('count(*) AS count')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?? 'Tanpa lokasi', 'count' => (int) $r->count])->all();
    }

    /**
     * Uniform chart payload: the configured visualization plus categories and one or more
     * series. `items` is [{label, value}] for single-measure charts.
     *
     * @param  list<array{label: string, value: float|int}>  $items
     * @return array<string, mixed>
     */
    private function chart(WidgetContext $ctx, array $items, string $key): array
    {
        return [
            'chart' => $ctx->config('visualization') ?? 'bar',
            'categories' => array_column($items, 'label'),
            'series' => [['key' => $key, 'label' => $key === 'value' ? 'Nilai' : 'Jumlah', 'data' => array_column($items, 'value')]],
        ];
    }

    /**
     * One monthly series per selected transaction type (system codes and organization types
     * alike), over the last `months` months.
     *
     * @return array<string, mixed>
     */
    public function transactionsMonthly(WidgetContext $ctx): array
    {
        $months = $ctx->intConfig('months') ?: 6;
        $start = $ctx->monthsStart($months);
        $rows = $ctx->transactions()->toBase()
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') AS month")
            ->selectRaw('type')->selectRaw('type_definition_id')
            ->selectRaw('count(*) AS count')->groupBy('month', 'type', 'type_definition_id')->get();

        $definitionIds = $rows->pluck('type_definition_id')->filter()->unique()->values();
        $definitions = $definitionIds->isEmpty() ? collect()
            : TransactionTypeDefinition::query()->whereIn('id', $definitionIds)->pluck('name', 'id');
        $labels = [];
        foreach ($rows as $row) {
            $labels[$this->typeKey($row->type, $row->type_definition_id)] = $row->type_definition_id !== null
                ? ($definitions[$row->type_definition_id] ?? 'Jenis organisasi')
                : (TransactionType::LABELS[$row->type] ?? $row->type);
        }

        // Top types by volume keep their own series; smaller ones still count via the rows below.
        $totals = [];
        foreach ($rows as $row) {
            $k = $this->typeKey($row->type, $row->type_definition_id);
            $totals[$k] = ($totals[$k] ?? 0) + (int) $row->count;
        }
        arsort($totals);
        $keys = array_slice(array_keys($totals), 0, 8);
        $categories = [];
        for ($i = 0; $i < $months; $i++) {
            $categories[] = $start->addMonths($i)->format('Y-m');
        }
        $series = [];
        foreach ($keys as $k) {
            $series[] = ['key' => $k, 'label' => $labels[$k] ?? $k, 'data' => array_fill(0, $months, 0)];
        }
        $seriesIndex = array_flip(array_column($series, 'key'));
        $byMonth = [];
        foreach ($rows as $row) {
            $k = $this->typeKey($row->type, $row->type_definition_id);
            $byMonth[$row->month][$k] = ($byMonth[$row->month][$k] ?? 0) + (int) $row->count;
        }
        foreach ($byMonth as $month => $counts) {
            $i = ((int) substr($month, 0, 4) - (int) $start->format('Y')) * 12 + ((int) substr($month, 5, 2) - (int) $start->format('m'));
            foreach ($counts as $k => $count) {
                if (isset($seriesIndex[$k]) && $i >= 0 && $i < $months) {
                    $series[$seriesIndex[$k]]['data'][$i] += $count;
                }
            }
        }

        return ['chart' => $ctx->config('visualization') ?? 'bar', 'categories' => $categories, 'series' => $series];
    }

    /** @return array<string, mixed> */
    public function outgoingVsIncoming(WidgetContext $ctx): array
    {
        $counts = $ctx->transactions()->toBase()
            ->whereIn('type', [TransactionType::OUTGOING, TransactionType::INCOMING])
            ->select('type', DB::raw('count(*) AS count'))->groupBy('type')->pluck('count', 'type');

        return $this->chart($ctx, [
            ['label' => 'Keluar', 'value' => (int) ($counts[TransactionType::OUTGOING] ?? 0)],
            ['label' => 'Masuk', 'value' => (int) ($counts[TransactionType::INCOMING] ?? 0)],
        ], 'count');
    }

    /** @return array<string, mixed> */
    public function recentTransactions(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $rows = $ctx->transactions()->with(['asset:id,name', 'creator:id,name', 'typeDefinition:id,name'])
            ->orderByDesc('transaction_date')->orderByDesc('seq')->limit($limit)->get();

        return [
            'count' => $ctx->transactions()->count(),
            'items' => $rows->map(fn ($t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'type' => $t->typeDefinition?->name ?? TransactionType::LABELS[$t->type] ?? $t->type,
                'transaction_date' => $t->transaction_date->toDateString(),
                'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name],
                'created_by' => $t->creator?->name,
            ])->all(),
        ];
    }

    /** Assets with the most transfer transactions in the period. */
    public function mostTransferred(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $rows = $ctx->transactions()->where('type', TransactionType::TRANSFER)->toBase()
            ->select('asset_id', DB::raw('count(*) AS count'))->groupBy('asset_id')
            ->orderByDesc('count')->limit($limit)->pluck('count', 'asset_id');
        $assets = Asset::query()->whereIn('id', $rows->keys())->with('location:id,name')->get()->keyBy('id');

        return [
            'columns' => [
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'location', 'label' => 'Lokasi'],
                ['key' => 'transfers', 'label' => 'Pemindahan'],
            ],
            'rows' => $rows->map(fn ($count, $id) => $assets->has($id) ? [
                'asset' => ['id' => $id, 'name' => $assets[$id]->name, 'asset_number' => $assets[$id]->asset_number],
                'location' => $assets[$id]->location?->name,
                'transfers' => (int) $count,
            ] : null)->filter()->values()->all(),
            'count' => (clone $ctx->transactions())->where('type', TransactionType::TRANSFER)->count(),
        ];
    }

    /** Temporary outgoing assets past their planned return date. */
    public function overdueOutgoingList(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $today = $ctx->today()->toDateString();
        $query = $this->openOutgoing($ctx)->where('due_date', '<', $today);

        $rows = (clone $query)->with('asset:id,name')->orderBy('due_date')->limit($limit)->get();

        return [
            'columns' => [
                ['key' => 'transaction', 'label' => 'Transaksi'],
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'counterparty', 'label' => 'Pihak luar'],
                ['key' => 'due_date', 'label' => 'Rencana kembali'],
                ['key' => 'days_late', 'label' => 'Terlambat'],
            ],
            'rows' => $rows->map(fn ($t) => [
                'transaction' => $t->transaction_number,
                'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name],
                'counterparty' => $t->counterparty_name,
                'due_date' => $t->due_date?->toDateString(),
                'days_late' => $t->due_date === null ? null : (int) CarbonImmutable::parse($t->due_date->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false),
            ])->all(),
            'count' => (clone $query)->count(),
        ];
    }

    /** Internal loans past their planned return date (the outgoing list covers outside parties). */
    public function overdueLoansList(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $today = $ctx->today();
        $query = fn () => $ctx->assets()->where('status', AssetStatus::ON_LOAN)->where('loan_due_date', '<', $today->toDateString());

        $rows = $query()->with('location:id,name')->orderBy('loan_due_date')->limit($limit)->get();

        return [
            'columns' => [
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'borrower', 'label' => 'Peminjam'],
                ['key' => 'location', 'label' => 'Lokasi'],
                ['key' => 'due_date', 'label' => 'Rencana kembali'],
                ['key' => 'days_late', 'label' => 'Terlambat'],
            ],
            'rows' => $rows->map(fn (Asset $a) => [
                'asset' => ['id' => $a->id, 'name' => $a->name, 'asset_number' => $a->asset_number],
                'borrower' => $a->borrower_name,
                'location' => $a->location?->name,
                'due_date' => $a->loan_due_date?->toDateString(),
                'days_late' => $a->loan_due_date === null ? null : (int) CarbonImmutable::parse($a->loan_due_date->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($today->toDateString(), 'UTC'), false),
            ])->all(),
            'count' => $query()->count(),
        ];
    }

    /** Loans and temporary outgoing assets due within `days_ahead` days. */
    public function dueSoonList(WidgetContext $ctx): array
    {
        $days = $ctx->intConfig('days_ahead') ?: 7;
        $limit = $ctx->intConfig('limit') ?: 10;
        $today = $ctx->today();
        $from = $today->toDateString();
        $to = $today->addDays($days)->toDateString();

        $loans = $ctx->assets()->where('status', AssetStatus::ON_LOAN)->whereBetween('loan_due_date', [$from, $to]);
        $outgoing = $this->openOutgoing($ctx)->whereBetween('due_date', [$from, $to]);

        $items = collect()
            ->concat((clone $loans)->orderBy('loan_due_date')->limit($limit)->get(['id', 'name', 'borrower_name', 'loan_due_date'])
                ->map(fn (Asset $a) => ['kind' => 'loan', 'asset' => ['id' => $a->id, 'name' => $a->name], 'party' => $a->borrower_name, 'due_date' => $a->loan_due_date?->toDateString()]))
            ->concat((clone $outgoing)->with('asset:id,name')->orderBy('due_date')->limit($limit)->get()
                ->map(fn ($t) => ['kind' => 'outgoing', 'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name], 'party' => $t->counterparty_name, 'due_date' => $t->due_date?->toDateString()]))
            ->sortBy('due_date')->take($limit)->values();

        return [
            'columns' => [
                ['key' => 'kind', 'label' => 'Jenis'],
                ['key' => 'asset', 'label' => 'Aset'],
                ['key' => 'party', 'label' => 'Peminjam / Pihak luar'],
                ['key' => 'due_date', 'label' => 'Jatuh tempo'],
            ],
            'rows' => $items->map(fn ($i) => [
                'kind' => $i['kind'] === 'loan' ? 'Peminjaman' : 'Keluar sementara',
                'asset' => $i['asset'], 'party' => $i['party'], 'due_date' => $i['due_date'],
            ])->all(),
            'count' => $loans->count() + $outgoing->count(),
            'days' => $days,
        ];
    }

    /**
     * Transactions of the organization's own types with their custom field values as columns
     * (F-07g, D-31): the fields of the configured types — or of every non-draft type — become
     * columns, values rendered like the transaction detail page.
     *
     * @return array<string, mixed>
     */
    public function customTransactions(WidgetContext $ctx): array
    {
        $limit = $ctx->intConfig('limit') ?: 10;
        $query = $ctx->transactions()->whereNotNull('type_definition_id')
            ->with(['asset:id,name', 'typeDefinition.fields']);

        $definitions = $this->widgetDefinitions($ctx);
        $columns = [
            ['key' => 'transaction', 'label' => 'Transaksi'],
            ['key' => 'date', 'label' => 'Tanggal'],
            ['key' => 'type', 'label' => 'Jenis'],
            ['key' => 'asset', 'label' => 'Aset'],
        ];
        $fieldColumns = [];
        foreach ($definitions as $definition) {
            foreach ($definition->fields as $field) {
                if (count($fieldColumns) >= 8) {
                    break 2;
                }
                $key = 'field.'.$definition->id.'.'.$field->key;
                $fieldColumns[$key] = [
                    'definition' => $definition,
                    'field' => $field,
                    'label' => count($definitions) > 1 ? $definition->name.': '.$field->label : $field->label,
                ];
                $columns[] = ['key' => $key, 'label' => $fieldColumns[$key]['label']];
            }
        }

        $rows = (clone $query)->orderByDesc('transaction_date')->orderByDesc('seq')->limit($limit)->get()
            ->map(function (AssetTransaction $t) use ($fieldColumns) {
                $definition = $t->typeDefinition;
                $values = $definition !== null ? TransactionFieldTypes::display($definition->fields, $t->field_values ?? []) : [];
                $row = [
                    'transaction' => $t->transaction_number,
                    'date' => $t->transaction_date?->toDateString(),
                    'type' => $definition?->name,
                    'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name],
                ];
                foreach ($fieldColumns as $key => $column) {
                    $row[$key] = $column['definition']->id === $definition?->id ? ($values[$column['field']->key] ?? null) : null;
                }

                return $row;
            })->all();

        return ['columns' => $columns, 'rows' => $rows, 'count' => (clone $query)->count()];
    }

    /** The type definitions whose fields become columns: the configured ones, else every usable type. */
    private function widgetDefinitions(WidgetContext $ctx): Collection
    {
        $configured = array_values(array_filter((array) $ctx->config('transaction_types'),
            fn ($v) => is_string($v) && ! in_array($v, TransactionType::ALL, true)));

        return TransactionTypeDefinition::query()->organizationOwn()->where('status', '!=', TransactionTypeDefinition::DRAFT)
            ->when($configured !== [], fn ($q) => $q->whereIn('id', $configured))
            ->with('fields')->orderBy('name')->get();
    }

    // ------------------------------------------------------------------ attribute helpers

    /**
     * Assets having a category attribute (by config key `attribute` or cross-category
     * `attribute_label`) whose stored value is one of $values. EXISTS keeps the asset query
     * untouched for the caller.
     */
    private function conditionValuesQuery(array $values): QueryBuilder
    {
        return DB::table('tenant_category_attributes as a')
            ->whereColumn('a.category_id', 'assets.category_id')
            ->where('a.status', 'active')
            ->where('a.label', CategoryAttributeService::CONDITION_LABEL)
            ->whereIn(DB::raw('assets.attribute_values ->> a.key'), $values);
    }

    /**
     * Label/value pairs of one attribute across the filtered assets: the configured
     * `attribute` (exact key) or `attribute_label` (case-insensitive label) resolved per
     * asset's category, restricted to the given data types. Enum values show their option
     * label when the attribute defines one.
     *
     * @param  list<string>|null  $label  fixed attribute label (e.g. Kondisi Aset); NULL uses the widget config
     * @param  list<string>  $dataTypes
     * @return list<array{label: string, value: float|int}>
     */
    private function attributeValues(WidgetContext $ctx, ?string $label, array $dataTypes, int $limit = 20): array
    {
        $base = $this->attributeBase($ctx, $dataTypes, $label);
        if ($base === null) {
            return [];
        }
        $value = 'assets.attribute_values ->> a.key';
        $display = "CASE WHEN a.data_type = 'enum' AND a.options IS NOT NULL THEN COALESCE((
            SELECT o->>'label' FROM jsonb_array_elements(a.options) o WHERE o->>'value' = {$value} LIMIT 1
        ), {$value}) ELSE {$value} END";

        return $base
            ->selectRaw("{$display} AS label")->selectRaw('count(*) AS count')
            ->whereRaw("{$value} IS NOT NULL AND {$value} <> ''")
            ->groupByRaw($display)->orderByDesc('count')->limit($limit)
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->count])->all();
    }

    /**
     * The filtered asset query joined to the configured attribute row. NULL when the widget
     * has no usable attribute configuration.
     */
    private function attributeBase(WidgetContext $ctx, array $dataTypes, ?string $fixedLabel = null): ?QueryBuilder
    {
        // `attribute` holds an exact attribute key (category dashboards resolve labels to
        // keys); `attribute_label` — written when a dashboard is captured into a template —
        // holds the label, matched case-insensitively per asset's category. Either may also
        // be supplied directly; a value matches a key first, then a label.
        $spec = $fixedLabel ?? $ctx->config('attribute') ?? $ctx->config('attribute_label');
        if ($spec === null) {
            return null;
        }

        return $ctx->assets()->toBase()
            ->join('tenant_category_attributes as a', function ($join) use ($spec, $dataTypes) {
                $join->on('a.category_id', '=', 'assets.category_id')->where('a.status', 'active')
                    ->where(fn ($w) => $w->where('a.key', $spec)->orWhereRaw('lower(a.label) = ?', [mb_strtolower((string) $spec)]));
                if ($dataTypes !== []) {
                    $join->whereIn('a.data_type', $dataTypes);
                }
            });
    }

    /** Shared open temporary-outgoing query (same rule as HomeCards). */
    private function openOutgoing(WidgetContext $ctx): Builder
    {
        return AssetTransaction::query()
            ->whereIn('asset_id', $ctx->assets()->where('status', AssetStatus::OFF_SITE)->select('assets.id'))
            ->where('type', TransactionType::OUTGOING)->where('outgoing_nature', OutgoingReason::TEMPORARY)
            ->whereDoesntHave('incomingTransaction');
    }

    private function monthly(WidgetContext $ctx, array $types, int $months): array
    {
        $start = $ctx->monthsStart($months);
        $counts = $ctx->transactions()->whereIn('type', $types)->toBase()
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') AS month, count(*) AS count")
            ->groupBy('month')->pluck('count', 'month');

        $categories = [];
        $data = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i)->format('Y-m');
            $categories[] = $month;
            $data[] = (int) ($counts[$month] ?? 0);
        }

        return ['chart' => $ctx->config('visualization') ?? 'line', 'categories' => $categories,
            'series' => [['key' => 'count', 'label' => 'Jumlah', 'data' => $data]]];
    }

    private function typeKey(?string $type, ?string $definitionId): string
    {
        return $definitionId !== null ? 'def:'.$definitionId : (string) $type;
    }
}
