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
        ];
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
