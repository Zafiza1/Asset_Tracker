<?php

namespace App\Domain\ReportDesigner\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\ReportDesigner\Binding\FieldResolver;
use App\Domain\ReportDesigner\Binding\ValueFormatter;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\Reports\AssetReport;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Tenant-scoped data access for report designs.
 *
 * Rows come only from the two real sources — the asset register (same query and
 * filters as the fixed Asset report, same AssetScope data-scope rules) and the
 * asset transaction history (scoped through its asset). Every value a template can
 * reach is a Field Registry entry; there is no free-form query path.
 */
final class ReportDataResolver
{
    /** Rows returned by preview/generate before the export layer pages further. */
    public const MAX_ROWS = 500;

    public function __construct(
        private readonly AssetScope $scope,
        private readonly Tenancy $tenancy,
        private readonly FieldResolver $fields,
        private readonly ValueFormatter $formatter,
    ) {}

    /** Validation rules for the `filters` object of preview/generate requests. */
    public static function filterRules(string $source): array
    {
        return match ($source) {
            'asset' => AssetReport::rules(),
            'asset_transaction' => [
                'search' => ['nullable', 'string', 'max:100'],
                'type' => ['nullable', Rule::in(TransactionType::ALL)],
                'asset_id' => ['nullable', 'uuid'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> validated filters (empty array for unknown sources) */
    public function filters(string $source, array $input): array
    {
        return validator($input, self::filterRules($source))->validate();
    }

    /**
     * Matching rows for a source, capped at MAX_ROWS, with total count and the
     * relations every catalog bind path needs already eager-loaded.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, Model>, total: int}
     */
    public function rows(string $source, array $filters): array
    {
        $query = $this->query($source, $filters);
        $total = (clone $query)->count();

        return [
            'rows' => $query->limit(self::MAX_ROWS)->get(),
            'total' => $total,
        ];
    }

    /** The scoped, filtered, ordered query for a source (before limit). */
    public function query(string $source, array $filters): Builder
    {
        return match ($source) {
            'asset' => $this->assetQuery($filters),
            'asset_transaction' => $this->transactionQuery($filters),
            default => throw new \InvalidArgumentException("Unknown report data source: {$source}"),
        };
    }

    /**
     * Resolve the registry fields a user may see for one row: field_key → display
     * string. Fields whose required_permission the user lacks are skipped and
     * reported in `restricted` instead of leaking data.
     *
     * @param  list<string>  $fieldKeys
     * @return array{values: array<string, string>, restricted: list<string>}
     */
    public function rowDisplay(Model $row, string $source, array $fieldKeys): array
    {
        $values = [];
        $restricted = [];
        $context = $this->tenancy->hasTenant() ? $this->tenancy->tenant() : null;

        foreach ($fieldKeys as $key) {
            if (! FieldCatalog::has($source, $key)) {
                continue;
            }
            $field = FieldCatalog::fields($source)[$key];
            $permission = $field[5] ?? null;
            // Fail closed: a required permission the context cannot confirm hides the field.
            if ($permission !== null && ($context === null || ! $context->can($permission))) {
                $restricted[] = $key;

                continue;
            }
            $values[$key] = $this->formatter->display(
                $this->fields->rowValue($row, $source, $key), $field, $key);
        }

        return ['values' => $values, 'restricted' => $restricted];
    }

    /** Raw values of the allowed source fields on one row (for conditions). */
    public function rowRaw(Model $row, string $source, array $fieldKeys): array
    {
        $raw = [];
        foreach ($fieldKeys as $key) {
            if (FieldCatalog::has($source, $key)) {
                $raw[$key] = $this->formatter->raw($this->fields->rowValue($row, $source, $key));
            }
        }

        return $raw;
    }

    /**
     * Context field values (organization.*, report.*) for the tenant, formatted.
     *
     * @param  list<string>  $fieldKeys  context field keys only
     * @return array{display: array<string, string>, raw: array<string, mixed>}
     */
    public function context(array $fieldKeys, string $reportTitle): array
    {
        $tenant = $this->tenancy->tenant();
        $organization = $tenant->organization;
        $display = [];
        $raw = [];

        foreach ($fieldKeys as $key) {
            if (! FieldCatalog::has('context', $key)) {
                continue;
            }
            $field = FieldCatalog::fields('context')[$key];
            $value = $this->formatter->contextValue($key, $organization->name, $organization->code, $reportTitle);
            $raw[$key] = $this->formatter->raw($value);
            $display[$key] = $this->formatter->display($value, $field, $key);
        }

        return ['display' => $display, 'raw' => $raw];
    }

    /** Split bound keys into source fields vs context fields. */
    public function splitBindings(array $bindings, string $source): array
    {
        $sourceKeys = [];
        $contextKeys = [];
        foreach ($bindings as $key) {
            if (FieldCatalog::has($source, $key)) {
                $sourceKeys[] = $key;
            } elseif (FieldCatalog::has('context', $key)) {
                $contextKeys[] = $key;
            }
        }

        return ['source' => array_values(array_unique($sourceKeys)), 'context' => array_values(array_unique($contextKeys))];
    }

    /** @param  array<string, mixed>  $filters */
    private function assetQuery(array $filters): Builder
    {
        // The fixed Asset report owns the filter semantics (category/location subtrees,
        // archived handling, cost range); reuse it so a custom report never disagrees
        // with the standard one. AssetReport already applies the user's AssetScope.
        return app(AssetReport::class)->query($filters)
            ->with(FieldResolver::EAGER_LOADS['asset']);
    }

    /** @param  array<string, mixed>  $filters */
    private function transactionQuery(array $filters): Builder
    {
        $query = AssetTransaction::query()
            ->whereIn('asset_id', $this->scope->apply(Asset::query())->select('id'))
            ->with(FieldResolver::EAGER_LOADS['asset_transaction'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('transaction_number', 'ilike', ListQuery::like($term))
                ->orWhere('notes', 'ilike', ListQuery::like($term))
                ->orWhere('borrower_name', 'ilike', ListQuery::like($term))
                ->orWhereHas('asset', fn ($a) => $a->where('name', 'ilike', ListQuery::like($term)))))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['asset_id'] ?? null, fn ($q, $id) => $q->where('asset_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('transaction_date', '<=', $d))
            ->orderByDesc('transaction_date')->orderByDesc('seq');

        return $query;
    }
}
