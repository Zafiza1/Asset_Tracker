<?php

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Everything a widget provider needs for one widget on one dashboard: the validated
 * configuration (schema defaults applied), the dashboard's category restriction, and the
 * member's data scope. Filters only ever narrow the scope — a configured location or
 * category outside the member's scope simply matches nothing.
 */
final class WidgetContext
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly Dashboard $dashboard,
        public readonly DashboardWidget $widget,
        public readonly WidgetCatalog $catalog,
        public readonly array $config,
        private readonly AssetScope $scope,
        private readonly Tenancy $tenancy,
    ) {}

    public function key(): string
    {
        return $this->catalog->widget_key;
    }

    /** A configuration value, or the schema default, or NULL. */
    public function config(string $key): mixed
    {
        $value = $this->config[$key] ?? null;
        if ($value === null) {
            $value = $this->catalog->configuration_schema['fields'][$key]['default'] ?? null;
        }

        return $value;
    }

    public function intConfig(string $key): int
    {
        return (int) $this->config($key);
    }

    /** The dashboard's category subtree intersected with the widget's own category filter. NULL = unrestricted. */
    public function categoryIds(): ?array
    {
        $ids = null;
        if ($this->dashboard->category_id !== null) {
            $ids = TenantAssetCategory::query()->where('id', $this->dashboard->category_id)
                ->orWhere('path', 'like', '%/'.$this->dashboard->category_id.'/%')->pluck('id')->all();
        }

        return $this->narrow($ids, $this->config('category_ids'));
    }

    /** The widget's location filter, already inside the member's scope by the query itself. */
    public function locationIds(): ?array
    {
        return $this->narrow(null, $this->config('location_ids'));
    }

    /** @return list<string>|null */
    public function statuses(): ?array
    {
        $value = $this->config('statuses');

        return is_array($value) && $value !== [] ? array_values($value) : null;
    }

    /** [from, to] ISO dates of the configured period, or NULL for "all". */
    public function period(): ?array
    {
        $days = match ($this->config('period')) {
            '7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365, default => null,
        };
        if ($days === null) {
            return null;
        }
        $today = $this->today();

        return [$today->subDays($days - 1)->toDateString(), $today->toDateString()];
    }

    /** First day of the month `months` months ago (inclusive). */
    public function monthsStart(int $months): CarbonImmutable
    {
        return $this->today()->startOfMonth()->subMonths($months - 1);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tenancy->tenant()->organization->timezone ?: 'UTC')->startOfDay();
    }

    /** In-scope, non-archived assets narrowed by the widget's filters. */
    public function assets(bool $includeArchived = false): Builder
    {
        $query = $this->scope->apply(Asset::query(), 'assets.location_id');
        if (! $includeArchived) {
            $query->whereNull('assets.archived_at');
        }
        if (($ids = $this->categoryIds()) !== null) {
            $query->whereIn('assets.category_id', $ids);
        }
        if (($ids = $this->locationIds()) !== null) {
            $query->whereIn('assets.location_id', $ids);
        }
        if (($statuses = $this->statuses()) !== null) {
            $query->whereIn('assets.status', $statuses);
        }

        return $query;
    }

    /** Transactions of in-scope assets (archived included: history does not disappear), narrowed like assets(). */
    public function transactions(): Builder
    {
        return AssetTransaction::query()
            ->whereIn('asset_id', $this->assets(includeArchived: true)->select('assets.id'))
            ->when($this->config('transaction_types'), function (Builder $q, array $types) {
                $custom = array_values(array_filter($types, fn ($t) => is_string($t) && ! in_array($t, TransactionType::ALL, true)));
                $system = array_values(array_filter($types, fn ($t) => is_string($t) && in_array($t, TransactionType::ALL, true)));
                $q->where(fn ($w) => $w
                    ->when($system !== [], fn ($s) => $s->whereIn('type', $system))
                    ->when($custom !== [], fn ($c) => $c->orWhereIn('type_definition_id', $custom)));
            })
            ->when($this->period(), fn (Builder $q, array $range) => $q->whereBetween('transaction_date', $range));
    }

    /**
     * Intersect a dashboard-level id list with the widget's configured filter. NULL means
     * "no restriction"; an empty array means "nothing matches" (a category dashboard and a
     * widget filter that do not overlap).
     *
     * @return list<string>|null
     */
    private function narrow(?array $base, mixed $configured): ?array
    {
        $configured = is_array($configured) && $configured !== [] ? array_values($configured) : null;
        if ($base === null) {
            return $configured;
        }
        if ($configured === null) {
            return $base;
        }

        return array_values(array_intersect($base, $configured));
    }
}
