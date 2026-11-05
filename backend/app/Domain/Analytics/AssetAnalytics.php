<?php

namespace App\Domain\Analytics;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Asset Analytics: dashboard figures computed from the stored assets and transactions
 * (never cached or static), limited to the user's data scope. Read-only.
 *
 * "Active inventory" = assets that are not archived. Archived assets only appear in the
 * archived counter; disposed assets remain in the inventory with status "Dihapuskan" until
 * they are deactivated.
 */
final class AssetAnalytics
{
    public const TREND_MONTHS = 12;

    public const RECENT_DAYS = 30;

    private const ATTENTION_LIMIT = 10;

    /** Loans due within this many days (today included) count as "due soon". */
    public const DUE_SOON_DAYS = 7;

    /** Available assets without any transaction for this many days count as idle. */
    public const IDLE_DAYS = 90;

    private const IDLE_LIMIT = 5;

    /** Age buckets by acquisition date: key => [from years (inclusive), to years (exclusive) or null, label]. */
    private const AGE_BUCKETS = [
        'lt1' => [0, 1, '< 1 tahun'],
        '1_3' => [1, 3, '1–3 tahun'],
        '3_5' => [3, 5, '3–5 tahun'],
        '5_10' => [5, 10, '5–10 tahun'],
        'gte10' => [10, null, '≥ 10 tahun'],
    ];

    /** Optional asset fields checked for data completeness: column => label. */
    private const QUALITY_FIELDS = [
        'location_id' => 'Lokasi',
        'acquisition_date' => 'Tanggal perolehan',
        'acquisition_cost' => 'Nilai perolehan',
        'serial_number' => 'Nomor seri',
        'image_path' => 'Foto aset',
    ];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly Tenancy $tenancy,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $today = CarbonImmutable::now($this->tenancy->tenant()->organization->timezone ?: 'UTC')->startOfDay();

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $this->summary($today),
            'by_status' => $this->byStatus(),
            'by_category' => $this->byCategory(),
            'by_location' => $this->byLocation(),
            'transactions' => $this->transactionSummary($today),
            'trend' => $this->trend($today),
            'attention' => $this->attention($today),
            'utilization' => $this->utilization(),
            'loans' => $this->loans($today),
            'age' => $this->age($today),
            'value_by_category' => $this->valueByCategory(),
            'data_quality' => $this->dataQuality(),
            'idle' => $this->idle($today),
        ];
    }

    /**
     * Share of operable assets (not lost or disposed) that are in service: in use or on loan.
     *
     * @return array{in_service: int, available: int, operable: int, rate: float|null}
     */
    private function utilization(): array
    {
        $row = $this->assets()->toBase()->selectRaw(<<<'SQL'
            count(*) FILTER (WHERE status IN (?, ?)) AS in_service,
            count(*) FILTER (WHERE status = ?) AS available,
            count(*) FILTER (WHERE status NOT IN (?, ?)) AS operable
        SQL, [AssetStatus::IN_USE, AssetStatus::ON_LOAN, AssetStatus::AVAILABLE, AssetStatus::LOST, AssetStatus::DISPOSED])->first();

        $operable = (int) $row->operable;

        return [
            'in_service' => (int) $row->in_service,
            'available' => (int) $row->available,
            'operable' => $operable,
            'rate' => $operable > 0 ? round((int) $row->in_service / $operable * 100, 1) : null,
        ];
    }

    /** @return array{active: int, overdue: int, due_soon: int, no_due_date: int, due_soon_days: int} */
    private function loans(CarbonImmutable $today): array
    {
        $date = $today->toDateString();
        $row = $this->assets()->where('assets.status', AssetStatus::ON_LOAN)->toBase()->selectRaw(<<<'SQL'
            count(*) AS active,
            count(*) FILTER (WHERE loan_due_date < ?) AS overdue,
            count(*) FILTER (WHERE loan_due_date BETWEEN ? AND ?) AS due_soon,
            count(*) FILTER (WHERE loan_due_date IS NULL) AS no_due_date
        SQL, [$date, $date, $today->addDays(self::DUE_SOON_DAYS - 1)->toDateString()])->first();

        return [
            'active' => (int) $row->active,
            'overdue' => (int) $row->overdue,
            'due_soon' => (int) $row->due_soon,
            'no_due_date' => (int) $row->no_due_date,
            'due_soon_days' => self::DUE_SOON_DAYS,
        ];
    }

    /**
     * Age of the assets still in service (not disposed) by acquisition date. Dates in the
     * future count as less than a year old.
     *
     * @return array{buckets: list<array{key: string, label: string, count: int}>, unknown: int, average_years: float|null}
     */
    private function age(CarbonImmutable $today): array
    {
        $date = $today->toDateString();
        $base = $this->assets()->where('assets.status', '!=', AssetStatus::DISPOSED);

        $years = (clone $base)->toBase()->whereNotNull('acquisition_date')
            ->selectRaw("greatest(0, date_part('year', age(cast(? AS date), acquisition_date)))::int AS years", [$date])
            ->selectRaw('count(*) AS count')
            ->groupBy('years')->pluck('count', 'years');

        $buckets = [];
        foreach (self::AGE_BUCKETS as $key => [$from, $to, $label]) {
            $count = $years->filter(fn ($c, $y) => $y >= $from && ($to === null || $y < $to))->sum();
            $buckets[] = ['key' => $key, 'label' => $label, 'count' => (int) $count];
        }

        $row = (clone $base)->toBase()->selectRaw(<<<'SQL'
            count(*) FILTER (WHERE acquisition_date IS NULL) AS unknown,
            avg(greatest(0, cast(? AS date) - acquisition_date)) AS average_days
        SQL, [$date])->first();

        return [
            'buckets' => $buckets,
            'unknown' => (int) $row->unknown,
            'average_years' => $row->average_days === null ? null : round((float) $row->average_days / 365.25, 1),
        ];
    }

    /**
     * Acquisition value per category, largest first; categories without any value are left out.
     *
     * @return list<array{id: string, name: string, value: string, count: int}>
     */
    private function valueByCategory(): array
    {
        return $this->assets()->toBase()
            ->join('tenant_asset_categories as c', 'c.id', '=', 'assets.category_id')
            ->whereNotNull('assets.acquisition_cost')
            ->groupBy('c.id', 'c.name')
            ->havingRaw('sum(assets.acquisition_cost) > 0')
            ->orderByDesc('value')->orderBy('c.name')
            ->get(['c.id', 'c.name', DB::raw('sum(assets.acquisition_cost) AS value'), DB::raw('count(*) AS count')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'value' => (string) $r->value, 'count' => (int) $r->count])->all();
    }

    /**
     * How many active assets have each optional field filled in.
     *
     * @return array{total: int, fields: list<array{key: string, label: string, filled: int}>, score: float|null}
     */
    private function dataQuality(): array
    {
        $select = ['count(*) AS total'];
        foreach (array_keys(self::QUALITY_FIELDS) as $column) {
            $filled = $column === 'serial_number' ? "nullif(trim({$column}), '') IS NOT NULL" : "{$column} IS NOT NULL";
            $select[] = "count(*) FILTER (WHERE {$filled}) AS {$column}";
        }
        $row = $this->assets()->toBase()->selectRaw(implode(', ', $select))->first();

        $total = (int) $row->total;
        $fields = [];
        foreach (self::QUALITY_FIELDS as $column => $label) {
            $fields[] = ['key' => $column, 'label' => $label, 'filled' => (int) $row->{$column}];
        }
        $slots = $total * count($fields);

        return [
            'total' => $total,
            'fields' => $fields,
            'score' => $slots > 0 ? round(array_sum(array_column($fields, 'filled')) / $slots * 100, 1) : null,
        ];
    }

    /**
     * Available assets with no transaction (by transaction date) for IDLE_DAYS days or more,
     * longest idle first: candidates for reassignment or disposal.
     *
     * @return array{days: int, count: int, items: list<array<string, mixed>>}
     */
    private function idle(CarbonImmutable $today): array
    {
        $activity = AssetTransaction::query()->toBase()
            ->select('asset_id', DB::raw('max(transaction_date) AS last_activity'))->groupBy('asset_id');
        $query = fn () => $this->assets()->where('assets.status', AssetStatus::AVAILABLE)
            ->joinSub($activity, 'act', 'act.asset_id', '=', 'assets.id')
            ->where('act.last_activity', '<=', $today->subDays(self::IDLE_DAYS)->toDateString());

        $items = $query()->with('location:id,name')
            ->select('assets.*', 'act.last_activity')
            ->orderBy('act.last_activity')->orderBy('assets.asset_number')->limit(self::IDLE_LIMIT)->get()
            ->map(fn (Asset $a) => [
                'asset' => ['id' => $a->id, 'asset_number' => $a->asset_number, 'name' => $a->name],
                'location' => $a->location?->name,
                'last_activity' => CarbonImmutable::parse($a->getAttribute('last_activity'))->toDateString(),
                'idle_days' => (int) CarbonImmutable::parse($a->getAttribute('last_activity'))->diffInDays($today),
            ])->all();

        return ['days' => self::IDLE_DAYS, 'count' => $query()->count(), 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function summary(CarbonImmutable $today): array
    {
        $row = $this->assets(includeArchived: true)->toBase()->selectRaw(<<<'SQL'
            count(*) FILTER (WHERE archived_at IS NULL) AS total,
            count(*) FILTER (WHERE archived_at IS NOT NULL) AS archived,
            coalesce(sum(acquisition_cost) FILTER (WHERE archived_at IS NULL), 0) AS total_value
        SQL)->first();

        $addedThisMonth = $this->transactions()->where('type', TransactionType::REGISTRATION)
            ->where('transaction_date', '>=', $today->startOfMonth()->toDateString())->count();

        return [
            'total' => (int) $row->total,
            'archived' => (int) $row->archived,
            'total_value' => (string) $row->total_value,
            'added_this_month' => $addedThisMonth,
            'currency' => $this->tenancy->tenant()->organization->currency,
        ];
    }

    /** @return list<array{status: string, label: string, count: int}> */
    private function byStatus(): array
    {
        $counts = $this->assets()->toBase()->select('status', DB::raw('count(*) AS count'))->groupBy('status')->pluck('count', 'status');

        return array_map(fn (string $s) => ['status' => $s, 'label' => AssetStatus::LABELS[$s], 'count' => (int) ($counts[$s] ?? 0)], AssetStatus::ALL);
    }

    /** @return list<array{id: string|null, name: string, count: int}> */
    private function byCategory(): array
    {
        return $this->assets()->toBase()
            ->join('tenant_asset_categories as c', 'c.id', '=', 'assets.category_id')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('count')->orderBy('c.name')
            ->get(['c.id', 'c.name', DB::raw('count(*) AS count')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->count])->all();
    }

    /** @return list<array{id: string|null, name: string, count: int}> */
    private function byLocation(): array
    {
        return $this->assets()->toBase()
            ->leftJoin('locations as l', 'l.id', '=', 'assets.location_id')
            ->groupBy('l.id', 'l.name')
            ->orderByDesc('count')->orderBy('l.name')
            ->get(['l.id', 'l.name', DB::raw('count(*) AS count')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?? 'Tanpa lokasi', 'count' => (int) $r->count])->all();
    }

    /** @return array<string, mixed> */
    private function transactionSummary(CarbonImmutable $today): array
    {
        $since = $today->subDays(self::RECENT_DAYS - 1)->toDateString();
        $counts = $this->transactions()->toBase()->where('transaction_date', '>=', $since)
            ->select('type', DB::raw('count(*) AS count'))->groupBy('type')->pluck('count', 'type');

        $recent = $this->transactions()->with(['asset:id,asset_number,name', 'creator:id,name'])
            ->orderByDesc('seq')->limit(8)->get()
            ->map(fn (AssetTransaction $t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'type' => $t->type,
                'transaction_date' => $t->transaction_date->toDateString(),
                'asset' => ['id' => $t->asset->id, 'asset_number' => $t->asset->asset_number, 'name' => $t->asset->name],
                'created_by' => $t->creator?->name,
            ])->all();

        return [
            'days' => self::RECENT_DAYS,
            'total' => (int) $counts->sum(),
            'by_type' => array_map(fn (string $type) => ['type' => $type, 'label' => TransactionType::LABELS[$type], 'count' => (int) ($counts[$type] ?? 0)], TransactionType::ALL),
            'recent' => $recent,
        ];
    }

    /**
     * Per month for the last TREND_MONTHS months: assets added (registrations) and changes
     * (every other transaction), by transaction date.
     *
     * @return list<array{month: string, added: int, changes: int}>
     */
    private function trend(CarbonImmutable $today): array
    {
        $start = $today->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        $rows = $this->transactions()->toBase()
            ->where('transaction_date', '>=', $start->toDateString())
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') AS month")
            ->selectRaw('count(*) FILTER (WHERE type = ?) AS added', [TransactionType::REGISTRATION])
            ->selectRaw('count(*) FILTER (WHERE type <> ?) AS changes', [TransactionType::REGISTRATION])
            ->groupBy('month')->get()->keyBy('month');

        $trend = [];
        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $month = $start->addMonths($i)->format('Y-m');
            $trend[] = ['month' => $month, 'added' => (int) ($rows[$month]->added ?? 0), 'changes' => (int) ($rows[$month]->changes ?? 0)];
        }

        return $trend;
    }

    /**
     * Assets that need follow-up, most urgent first.
     *
     * @return array{counts: array<string, int>, items: list<array<string, mixed>>}
     */
    private function attention(CarbonImmutable $today): array
    {
        $date = $today->toDateString();
        $rules = [
            'overdue_loan' => fn (Builder $q) => $q->where('status', AssetStatus::ON_LOAN)->whereNotNull('loan_due_date')->where('loan_due_date', '<', $date),
            'under_maintenance' => fn (Builder $q) => $q->where('status', AssetStatus::UNDER_MAINTENANCE),
            'no_location' => fn (Builder $q) => $q->whereNull('location_id')->where('status', '!=', AssetStatus::DISPOSED),
        ];

        $counts = [];
        $items = [];
        $seen = [];
        foreach ($rules as $reason => $rule) {
            $counts[$reason] = $rule($this->assets())->count();
            if (count($items) >= self::ATTENTION_LIMIT) {
                continue;
            }
            $assets = $rule($this->assets())->with('location:id,name')
                ->orderBy('loan_due_date')->orderBy('asset_number')->limit(self::ATTENTION_LIMIT)->get();
            foreach ($assets as $asset) {
                if (isset($seen[$asset->id]) || count($items) >= self::ATTENTION_LIMIT) {
                    continue;
                }
                $seen[$asset->id] = true;
                $items[] = [
                    'reason' => $reason,
                    'asset' => ['id' => $asset->id, 'asset_number' => $asset->asset_number, 'name' => $asset->name],
                    'status' => $asset->status,
                    'location' => $asset->location?->name,
                    'borrower_name' => $asset->borrower_name,
                    'loan_due_date' => $asset->loan_due_date?->toDateString(),
                ];
            }
        }

        return ['counts' => $counts, 'items' => $items];
    }

    private function assets(bool $includeArchived = false): Builder
    {
        $query = $this->scope->apply(Asset::query(), 'assets.location_id');

        return $includeArchived ? $query : $query->whereNull('assets.archived_at');
    }

    /** Transactions of assets in the user's scope (by the asset's current location). */
    private function transactions(): Builder
    {
        return AssetTransaction::query()->whereIn('asset_id', $this->assets(includeArchived: true)->select('assets.id'));
    }
}
