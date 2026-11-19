<?php

namespace App\Domain\ReportDesigner\Binding;

use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\ReportDesigner\Catalog\TransactionReportFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Resolves a registry field key to its raw value on a source row.
 *
 * Only paths declared in FieldCatalog are ever walked: the catalog's bind_path is an
 * allowlist, so a template can reach exactly the columns and relations the registry
 * exposes — nothing else. Relations are loaded lazily per row when missing; callers
 * should eager-load the fixed relation set per source to avoid N+1.
 */
final class FieldResolver
{
    /** Eager-loadable relations each row source needs for its catalog bind paths. */
    public const EAGER_LOADS = [
        'asset' => ['category', 'location'],
        'asset_transaction' => ['asset.category', 'asset.location', 'fromLocation', 'toLocation', 'creator', 'incomingTransaction', 'outgoingTransaction', 'typeDefinition.fields'],
    ];

    /**
     * Raw (unformatted) value of a source field on a row, or null when the path is
     * unknown or resolves to nothing. Unknown keys never throw — validation reports
     * them earlier; resolution is fail-closed.
     */
    public function rowValue(Model $row, string $source, string $fieldKey): mixed
    {
        // Organization transaction-type fields (F-07g): the row's type code must match
        // and the value comes from its stored field_values — never a dot path.
        if ($source === 'asset_transaction' && TransactionReportFields::parse($fieldKey) !== null) {
            return $this->customTransactionValue($row, $fieldKey);
        }

        $field = FieldCatalog::fields($source)[$fieldKey] ?? null;
        if ($field === null) {
            return null;
        }

        return $this->walk($row, explode('.', $field[2]));
    }

    /**
     * One custom transaction field of one row: scalar types pass their stored value to the
     * formatter; choice/reference types resolve through TransactionFieldTypes::display()
     * (option labels, member/location names, Ya/Tidak) like the transaction detail page.
     */
    private function customTransactionValue(Model $row, string $fieldKey): mixed
    {
        if (! $row instanceof AssetTransaction) {
            return null;
        }
        [$code, $key] = TransactionReportFields::parse($fieldKey);
        $definition = $row->typeDefinition;
        if ($definition === null || strtoupper($definition->code) !== strtoupper($code)) {
            return null;
        }
        $field = $definition->fields->firstWhere('key', $key);
        if ($field === null) {
            return null;
        }
        $values = $row->field_values ?? [];
        if (TransactionReportFields::isDisplayType($field)) {
            return TransactionFieldTypes::display([$field], $values)[$key] ?? null;
        }

        return $values[$key] ?? null;
    }

    /**
     * Walk a dot bind path from the row root. Each segment is either a relation
     * (camelCased method returning a Model/null) or an attribute/array key.
     * A trailing `[]` marks a repeatable child — V1 has none, so it unwraps to the
     * raw array for `array`-typed fields.
     */
    private function walk(mixed $current, array $segments): mixed
    {
        foreach ($segments as $segment) {
            $repeatable = str_ends_with($segment, '[]');
            $segment = rtrim($segment, '[]');
            if ($segment === '' || $current === null) {
                return null;
            }

            if ($current instanceof Model) {
                $current = $this->step($current, $segment);
            } elseif (is_array($current)) {
                $current = $current[$segment] ?? null;
            } else {
                return null;
            }

            if ($repeatable && is_array($current)) {
                // Repeatable children are not in V1; keep the collection for array fields.
                return $current;
            }
        }

        return $current;
    }

    private function step(Model $model, string $segment): mixed
    {
        // Relation names in bind paths are snake_case (from_location → fromLocation).
        $relation = Str::camel($segment);
        if (method_exists($model, $relation)) {
            try {
                $value = $model->getRelationValue($relation);
                if ($value !== null || $model->relationLoaded($relation)) {
                    return $value;
                }
            } catch (\Throwable) {
                // Not a relation — fall through to the attribute.
            }
        }

        return $model->getAttribute($segment);
    }
}
