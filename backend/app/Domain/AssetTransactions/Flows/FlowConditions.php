<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\Models\Asset;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Collection;

/**
 * Evaluates the rules of a condition or start step (F-07 §4.2) against a transaction: its assets,
 * the values entered so far and the roles of the user who started it. Only the subjects and
 * operators listed in FlowGraph exist; nothing is evaluated as code.
 *
 * With several assets, asset subjects are read per asset: `any` asset or `all` assets must pass;
 * `total` adds the acquisition cost of every asset before comparing.
 */
final class FlowConditions
{
    /**
     * @param  list<array{subject: string, op: string, value?: mixed}>  $rules
     * @param  Collection<int, Asset>  $assets  with category (path) loaded
     * @param  array<string, mixed>  $values  entered field values
     * @param  list<string>  $starterRoles  role ids of the user who started the transaction
     */
    public static function passes(array $rules, string $match, string $assetsMode, Collection $assets, array $values, array $starterRoles): bool
    {
        if ($rules === []) {
            return true;
        }
        $results = array_map(fn (array $rule) => self::rule($rule, $assetsMode, $assets, $values, $starterRoles), $rules);

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** @param array{subject: string, op: string, value?: mixed} $rule */
    private static function rule(array $rule, string $assetsMode, Collection $assets, array $values, array $starterRoles): bool
    {
        $subject = $rule['subject'];
        $op = $rule['op'];
        $expected = $rule['value'] ?? null;

        if (str_starts_with($subject, 'field.')) {
            return self::compare($values[substr($subject, 6)] ?? null, $op, $expected);
        }
        if ($subject === 'starter.role') {
            return self::compare($starterRoles, $op, $expected);
        }
        if ($subject === 'asset.acquisition_cost' && $assetsMode === 'total') {
            return self::compare($assets->sum(fn (Asset $a) => (float) $a->acquisition_cost), $op, $expected);
        }

        $check = fn (Asset $asset) => self::compare(self::assetValue($asset, $subject), $op, $expected, $subject);
        if ($assets->isEmpty()) {
            return false;
        }

        return $assetsMode === 'all' ? $assets->every($check) : $assets->contains($check);
    }

    private static function assetValue(Asset $asset, string $subject): mixed
    {
        return match ($subject) {
            'asset.status' => $asset->status,
            'asset.location' => $asset->location_id,
            'asset.acquisition_cost' => $asset->acquisition_cost === null ? null : (float) $asset->acquisition_cost,
            // The category and its ancestors: "category is Vehicles" also holds for Cars.
            'asset.category' => array_values(array_filter(explode('/', (string) $asset->category?->path))),
            // The option value of the asset's default "Kondisi Aset" field (e.g. RUSAK_BERAT).
            'asset.condition' => ($key = app(CategoryAttributeService::class)->conditionAttribute($asset->category_id)?->key) === null
                ? null : ($asset->attribute_values[$key] ?? null),
            default => null,
        };
    }

    private static function compare(mixed $actual, string $op, mixed $expected, ?string $subject = null): bool
    {
        $isEmpty = $actual === null || $actual === '' || $actual === [];
        if ($op === 'empty') {
            return $isEmpty;
        }
        if ($op === 'not_empty') {
            return ! $isEmpty;
        }
        if ($isEmpty) {
            return $op === 'neq' || $op === 'not_in';
        }

        $list = array_map(fn ($v) => is_bool($v) ? $v : (string) $v, is_array($expected) ? $expected : [$expected]);
        // Lists (multi-select values, a category with its ancestors, roles) match when they share a value.
        $actualList = is_array($actual) ? array_map(fn ($v) => (string) $v, $actual) : null;
        $member = fn () => $actualList !== null
            ? array_intersect($actualList, $list) !== []
            : in_array(is_bool($actual) ? $actual : (string) $actual, $list, true);

        return match ($op) {
            'eq', 'in' => $member(),
            'neq', 'not_in' => ! $member(),
            'gt', 'gte', 'lt', 'lte' => is_numeric($actual) && is_numeric($expected) && match ($op) {
                'gt' => (float) $actual > (float) $expected,
                'gte' => (float) $actual >= (float) $expected,
                'lt' => (float) $actual < (float) $expected,
                'lte' => (float) $actual <= (float) $expected,
            },
            default => false,
        };
    }
}
