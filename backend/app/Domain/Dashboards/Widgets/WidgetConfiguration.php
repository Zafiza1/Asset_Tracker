<?php

namespace App\Domain\Dashboards\Widgets;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Organization\Models\Location;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates a widget's `configuration` against its catalog `configuration_schema` — pure
 * data, never executed code (SystemWidgets documents the field types). Unknown keys and
 * values outside the declared options are rejected; every accepted value is additionally
 * checked to exist inside the organization (categories, locations, transaction types,
 * report templates), so a widget can never point at another tenant's row.
 */
final class WidgetConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, mixed>>  $fields  the schema's `fields` map
     * @return array<string, mixed> the validated configuration, schema defaults applied for missing keys
     */
    public static function validate(array $configuration, array $fields): array
    {
        $unknown = array_diff(array_keys($configuration), array_keys($fields));
        if ($unknown !== []) {
            throw ApiException::unprocessable('INVALID_WIDGET_CONFIGURATION', 'Konfigurasi widget berisi kolom yang tidak dikenal.', ['fields' => array_values($unknown)]);
        }

        $rules = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = self::rulesFor($field);
        }

        $validator = Validator::make($configuration, $rules);
        if ($validator->fails()) {
            throw ApiException::unprocessable('INVALID_WIDGET_CONFIGURATION', 'Konfigurasi widget tidak valid.', $validator->errors()->toArray());
        }

        $clean = [];
        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $configuration)) {
                $clean[$key] = $configuration[$key];
            } elseif (array_key_exists('default', $field)) {
                $clean[$key] = $field['default'];
            }
        }

        self::assertReferences($clean, $fields);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<mixed>
     */
    private static function rulesFor(array $field): array
    {
        // A declared default makes the key optional; otherwise NULL is accepted (the widget
        // then behaves as "no filter") but a present value must be well-formed.
        $required = array_key_exists('default', $field) ? 'sometimes' : 'nullable';

        return match ($field['type'] ?? null) {
            'category_ids', 'location_ids' => [$required, 'array', 'max:100'],
            'enum' => [$required, 'string', Rule::in($field['options'] ?? [])],
            'enum_list' => [$required, 'array', 'max:50'],
            'integer' => [$required, 'integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 100000)],
            'boolean' => [$required, 'boolean'],
            'text' => [$required, 'string', 'max:'.($field['max'] ?? 2000)],
            'attribute' => [$required, 'string', 'max:150'],
            'transaction_types' => [$required, 'array', 'max:50'],
            'report_template' => [$required, 'uuid'],
            default => throw new \LogicException('Unknown widget field type.'.json_encode($field)),
        };
    }

    /**
     * Referential checks that plain rules cannot express: the ids must belong to this
     * organization.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, array<string, mixed>>  $fields
     */
    private static function assertReferences(array $configuration, array $fields): void
    {
        $errors = [];
        foreach ($fields as $key => $field) {
            $value = $configuration[$key] ?? null;
            if ($value === null) {
                continue;
            }
            match ($field['type'] ?? null) {
                'category_ids' => self::idsExist($errors, $key, $value, TenantAssetCategory::query()),
                'location_ids' => self::idsExist($errors, $key, $value, Location::query()),
                'enum_list' => self::enumSubset($errors, $key, $value, $field['options'] ?? []),
                'transaction_types' => self::transactionTypesExist($errors, $key, $value),
                'report_template' => self::reportTemplateExists($errors, $key, $value),
                default => null,
            };
        }
        if ($errors !== []) {
            throw ApiException::unprocessable('INVALID_WIDGET_CONFIGURATION', 'Konfigurasi widget merujuk data yang tidak ditemukan.', $errors);
        }
    }

    /** @param  array<string, list<string>>  $errors */
    private static function idsExist(array &$errors, string $key, mixed $value, $query): void
    {
        if (! is_array($value)) {
            return;
        }
        $ids = array_values(array_unique(array_map('strval', $value)));
        if ($query->whereIn('id', $ids)->count() !== count($ids)) {
            $errors[$key] = ['Beberapa kategori atau lokasi tidak ditemukan.'];
        }
    }

    /** @param  array<string, list<string>>  $errors */
    private static function enumSubset(array &$errors, string $key, mixed $value, array $options): void
    {
        if (! is_array($value) || array_diff($value, $options) !== []) {
            $errors[$key] = ['Pilihan tidak valid.'];
        }
    }

    /** @param  array<string, list<string>>  $errors */
    private static function transactionTypesExist(array &$errors, string $key, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        $custom = array_values(array_filter($value, fn ($v) => is_string($v) && ! in_array($v, TransactionType::ALL, true)));
        if ($custom !== [] && TransactionTypeDefinition::query()->whereIn('id', array_unique($custom))->count() !== count(array_unique($custom))) {
            $errors[$key] = ['Jenis transaksi tidak ditemukan.'];
        }
    }

    /** @param  array<string, list<string>>  $errors */
    private static function reportTemplateExists(array &$errors, string $key, mixed $value): void
    {
        if (! is_string($value) || ! ReportTemplate::query()->whereKey($value)->exists()) {
            $errors[$key] = ['Laporan tidak ditemukan.'];
        }
    }
}
