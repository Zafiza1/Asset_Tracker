<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\AssetAnalytics;
use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Organization\Models\Location;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Asset report: the asset register filtered by category, location, status, activity and
 * acquisition date, limited to the user's data scope, with totals for the same selection.
 * The on-screen report and both exports (PDF, Excel) read the same query, so the numbers match.
 */
final class AssetReport
{
    public const SORTS = ['asset_number' => 'asset_number', 'name' => 'name', 'acquisition_date' => 'acquisition_date',
        'acquisition_cost' => 'acquisition_cost', 'status' => 'status', 'created_at' => 'created_at'];

    public const DEFAULT_SORT = 'asset_number';

    public function __construct(private readonly AssetScope $scope) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // Comma-separated; a category also matches its subcategories.
            'category_ids' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(AssetStatus::ALL)],
            'archived' => ['nullable', Rule::in(['only', 'all'])],
            'acquired_from' => ['nullable', 'date_format:Y-m-d'],
            'acquired_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:acquired_from'],
            // An optional field left empty (same keys as the dashboard's "Kelengkapan data").
            'missing' => ['nullable', Rule::in(array_keys(AssetAnalytics::QUALITY_FIELDS))],
            // Acquisition cost range in rupiah (inclusive); assets without a cost never match.
            'cost_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'cost_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999999', 'gte:cost_min'],
        ];
    }

    /**
     * Scoped assets matching the (validated) filters, with category and location loaded.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters): Builder
    {
        $categoryIds = $this->categoryIds($filters);

        $query = $this->scope->apply(Asset::query())->with(['category', 'location'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('assets.name', 'ilike', ListQuery::like($term))
                ->orWhere('assets.serial_number', 'ilike', ListQuery::like($term))))
            ->when($categoryIds, fn ($q) => $q->whereIn('assets.category_id', $this->subtree(TenantAssetCategory::query(), $categoryIds)))
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->whereIn('assets.location_id', $this->subtree(Location::query(), [$id])))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('assets.status', $status))
            ->when($filters['acquired_from'] ?? null, fn ($q, $date) => $q->whereDate('assets.acquisition_date', '>=', $date))
            ->when($filters['acquired_to'] ?? null, fn ($q, $date) => $q->whereDate('assets.acquisition_date', '<=', $date))
            ->when(self::filled($filters, 'cost_min'), fn ($q) => $q->where('assets.acquisition_cost', '>=', $filters['cost_min']))
            ->when(self::filled($filters, 'cost_max'), fn ($q) => $q->where('assets.acquisition_cost', '<=', $filters['cost_max']))
            ->when($filters['missing'] ?? null, fn ($q, $field) => $q->whereRaw('NOT ('.AssetAnalytics::filledCondition($field).')'));

        match ($filters['archived'] ?? null) {
            'only' => $query->whereNotNull('assets.archived_at'),
            'all' => null,
            default => $query->whereNull('assets.archived_at'),
        };

        return $query;
    }

    /**
     * Totals of the selection: count, acquisition value, and breakdowns by status, category and location.
     * `total_value` sums the cost of the `valued` assets only; assets without a cost add nothing,
     * and archived assets count only when the selection includes them.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = fn () => $this->query($filters)->toBase();

        $row = $base()->selectRaw('count(*) AS total, coalesce(sum(acquisition_cost), 0) AS total_value,
            count(acquisition_cost) AS valued, count(*) FILTER (WHERE archived_at IS NOT NULL) AS archived')->first();

        $statusCounts = $base()->select('status', DB::raw('count(*) AS count'))->groupBy('status')->pluck('count', 'status');

        $byCategory = $base()
            ->join('tenant_asset_categories as c', 'c.id', '=', 'assets.category_id')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('count')->orderBy('c.name')
            ->get(['c.id', 'c.name', DB::raw('count(*) AS count'), DB::raw('coalesce(sum(assets.acquisition_cost), 0) AS value')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->count, 'value' => (string) $r->value])->all();

        // Assets without a location get their own row (id null), so the rows add up to the total.
        $byLocation = $base()
            ->leftJoin('locations as l', 'l.id', '=', 'assets.location_id')
            ->groupBy('l.id', 'l.name')
            ->orderByDesc('count')->orderBy('l.name')
            ->get(['l.id', 'l.name', DB::raw('count(*) AS count'), DB::raw('coalesce(sum(assets.acquisition_cost), 0) AS value')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name ?? 'Tanpa lokasi', 'count' => (int) $r->count, 'value' => (string) $r->value])->all();

        return [
            'total' => (int) $row->total,
            'archived' => (int) $row->archived,
            'total_value' => (string) $row->total_value,
            'valued' => (int) $row->valued,
            'by_status' => array_map(fn (string $s) => ['status' => $s, 'label' => AssetStatus::LABELS[$s], 'count' => (int) ($statusCounts[$s] ?? 0)], AssetStatus::ALL),
            'by_category' => $byCategory,
            'by_location' => $byLocation,
        ];
    }

    /**
     * The applied filters in words, printed on the exported report.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function describe(array $filters): array
    {
        $lines = [];
        if ($filters['search'] ?? null) {
            $lines[] = 'Pencarian: "'.$filters['search'].'"';
        }
        if ($ids = $this->categoryIds($filters)) {
            $lines[] = 'Kategori: '.TenantAssetCategory::query()->whereKey($ids)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($filters['location_id'] ?? null) {
            $lines[] = 'Lokasi: '.(Location::query()->whereKey($filters['location_id'])->value('name') ?? '-');
        }
        if ($filters['status'] ?? null) {
            $lines[] = 'Status: '.AssetStatus::LABELS[$filters['status']];
        }
        $lines[] = match ($filters['archived'] ?? null) {
            'only' => 'Aset: nonaktif',
            'all' => 'Aset: aktif & nonaktif',
            default => 'Aset: aktif',
        };
        $from = $filters['acquired_from'] ?? null;
        $to = $filters['acquired_to'] ?? null;
        if ($from || $to) {
            $lines[] = 'Tanggal perolehan: '.($from ? self::date($from) : '…').' s.d. '.($to ? self::date($to) : '…');
        }
        $min = self::filled($filters, 'cost_min') ? self::money($filters['cost_min']) : null;
        $max = self::filled($filters, 'cost_max') ? self::money($filters['cost_max']) : null;
        if ($min || $max) {
            $lines[] = 'Nilai perolehan: '.($min ?? '…').' s.d. '.($max ?? '…');
        }
        if ($filters['missing'] ?? null) {
            $lines[] = 'Belum diisi: '.AssetAnalytics::QUALITY_FIELDS[$filters['missing']];
        }

        return $lines;
    }

    public static function date(?string $value): string
    {
        return $value ? date('d/m/Y', strtotime($value)) : '';
    }

    /**
     * How the total acquisition value was computed, printed under the totals of the exports.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function valueNote(array $summary): string
    {
        $unvalued = $summary['total'] - $summary['valued'];
        $note = 'Total nilai perolehan = jumlah nilai perolehan '.number_format($summary['valued'], 0, ',', '.')
            .' dari '.number_format($summary['total'], 0, ',', '.').' aset sesuai filter (nilai saat perolehan, tanpa penyusutan).';

        return $unvalued > 0 ? $note.' '.number_format($unvalued, 0, ',', '.').' aset belum memiliki nilai perolehan dan tidak dihitung.' : $note;
    }

    public static function money(mixed $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    /**
     * A numeric filter that was given (0 counts; null and '' do not).
     *
     * @param  array<string, mixed>  $filters
     */
    private static function filled(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== '';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function categoryIds(array $filters): array
    {
        return array_values(array_filter(explode(',', (string) ($filters['category_ids'] ?? ''))));
    }

    /**
     * Ids of the given tree nodes and all their descendants (materialized path).
     *
     * @param  list<string>  $ids
     */
    private function subtree(Builder $query, array $ids): Builder
    {
        return $query->select('id')->where(function ($w) use ($ids) {
            foreach ($ids as $id) {
                $w->orWhere('path', 'like', '%/'.$id.'/%');
            }
        });
    }
}
