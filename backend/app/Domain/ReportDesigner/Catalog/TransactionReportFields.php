<?php

namespace App\Domain\ReportDesigner\Catalog;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * The organization's own transaction-type fields as report fields (F-07g, D-31): every
 * field of every non-draft type of the current organization becomes a bindable field of
 * the `asset_transaction` source, keyed `transaction.custom.{TYPE_CODE}.{field_key}`.
 *
 * These are dynamic entries — they live in no registry table, so they exist only while the
 * tenant context does, and only for types of that organization. Resolution happens in
 * FieldResolver (the row's type code must match, then the stored field_values entry is
 * read); validation happens through FieldCatalog::fields(), which merges this map.
 */
final class TransactionReportFields
{
    public const PREFIX = 'transaction.custom.';

    /** Field types whose stored value is shown through TransactionFieldTypes::display(). */
    private const DISPLAY_TYPES = ['select', 'multiselect', 'user', 'location', 'counterparty', 'attachment'];

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * field_key => catalog tuple [label, data_type, bind_path, relation, formats, permission].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: null, 4: array<string, mixed>, 5: string}>
     */
    /** @var array<string, array<string, array<int, mixed>>> per-organization memo (tests switch tenants in one process) */
    private static array $memo = [];

    public function fields(): array
    {
        if (! $this->tenancy->hasTenant()) {
            return [];
        }
        $orgId = $this->tenancy->organizationId();
        if (isset(self::$memo[$orgId])) {
            return self::$memo[$orgId];
        }

        $fields = [];
        TransactionTypeDefinition::query()->where('status', '!=', TransactionTypeDefinition::DRAFT)
            ->with('fields')->get()
            ->each(function (TransactionTypeDefinition $definition) use (&$fields) {
                foreach ($definition->fields as $field) {
                    $fields[self::key($definition->code, $field->key)] = self::catalogEntry($definition, $field);
                }
            });

        return self::$memo[$orgId] = $fields;
    }

    public function has(string $fieldKey): bool
    {
        return isset($this->fields()[$fieldKey]);
    }

    /** The catalog tuple of one dynamic field, or NULL. */
    public function entry(string $fieldKey): ?array
    {
        return $this->fields()[$fieldKey] ?? null;
    }

    /** [type code, field key] of a `transaction.custom.` key, or NULL when malformed. */
    public static function parse(string $fieldKey): ?array
    {
        if (! str_starts_with($fieldKey, self::PREFIX)) {
            return null;
        }
        $rest = substr($fieldKey, strlen(self::PREFIX));
        if (! str_contains($rest, '.')) {
            return null;
        }
        [$code, $key] = explode('.', $rest, 2);
        if ($code === '' || $key === '') {
            return null;
        }

        return [$code, $key];
    }

    public static function key(string $typeCode, string $fieldKey): string
    {
        return self::PREFIX.$typeCode.'.'.$fieldKey;
    }

    /** Whether the field's stored value is resolved through TransactionFieldTypes::display(). */
    public static function isDisplayType(TransactionTypeField $field): bool
    {
        return in_array($field->data_type, self::DISPLAY_TYPES, true);
    }

    /** @return array{0: string, 1: string, 2: string, 3: null, 4: array<string, mixed>, 5: string} */
    private static function catalogEntry(TransactionTypeDefinition $definition, TransactionTypeField $field): array
    {
        $settings = $field->settings ?? [];

        return match ($field->data_type) {
            'number' => [$definition->name.' — '.$field->label, 'number', 'custom.'.$definition->code.'.'.$field->key, null,
                ['decimals' => (int) ($settings['decimals'] ?? 0), 'fallback' => '-'], 'transaction.view'],
            'money' => [$definition->name.' — '.$field->label, 'currency', 'custom.'.$definition->code.'.'.$field->key, null,
                ['currency' => 'IDR', 'decimals' => 0, 'fallback' => '-'], 'transaction.view'],
            'date' => [$definition->name.' — '.$field->label, 'date', 'custom.'.$definition->code.'.'.$field->key, null,
                ['date' => 'd/m/Y', 'fallback' => '-'], 'transaction.view'],
            'datetime' => [$definition->name.' — '.$field->label, 'date', 'custom.'.$definition->code.'.'.$field->key, null,
                ['date' => 'd/m/Y H:i', 'fallback' => '-'], 'transaction.view'],
            'boolean' => [$definition->name.' — '.$field->label, 'boolean', 'custom.'.$definition->code.'.'.$field->key, null,
                ['fallback' => '-'], 'transaction.view'],
            default => [$definition->name.' — '.$field->label, 'string', 'custom.'.$definition->code.'.'.$field->key, null,
                ['fallback' => '-'], 'transaction.view'],
        };
    }
}
