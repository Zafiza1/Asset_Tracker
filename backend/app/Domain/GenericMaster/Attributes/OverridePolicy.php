<?php

namespace App\Domain\GenericMaster\Attributes;

/**
 * Tenant overrides of a generic attribute (docs/03-database-erd.md §2).
 *
 * A property can be overridden only if it is both in the system allowlist and listed in
 * the generic attribute's `overridable`. Overrides may only specialize, never loosen:
 *  - required:   false -> true only;
 *  - options:    a non-empty subset of the generic values (generic labels and order kept);
 *  - validation: only narrowing min/max/min_length/max_length/decimals (pattern is fixed);
 *  - label, help_text, default_value: free, but the default must satisfy the effective rules.
 * data_type, key, unit and options' values themselves can never change.
 */
final class OverridePolicy
{
    public const ALLOWLIST = ['default_value', 'help_text', 'label', 'options', 'required', 'validation'];

    public function __construct(
        private readonly AttributeDefinitionRules $rules,
        private readonly AttributeValueValidator $values,
    ) {}

    /** @return list<string> */
    public static function narrowableKeys(string $type): array
    {
        return array_values(array_diff(AttributeType::VALIDATION_KEYS[$type] ?? [], ['pattern']));
    }

    /**
     * @param  array<string, mixed>  $base  generic attribute spec (effective-schema shape plus `overridable`)
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>} effective spec, issues, overridden properties
     */
    public function apply(array $base, array $overrides): array
    {
        $spec = $base;
        $issues = [];
        $applied = [];
        $type = $base['data_type'];

        foreach ($overrides as $property => $value) {
            if (! in_array($property, self::ALLOWLIST, true)) {
                $issues[] = "Properti '{$property}' tidak dapat di-override.";

                continue;
            }
            if (! in_array($property, $base['overridable'] ?? [], true)) {
                $issues[] = "Generic Master tidak mengizinkan override '{$property}'.";

                continue;
            }

            $error = match ($property) {
                'label' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 100 ? null : 'Label wajib diisi (maks 100 karakter).',
                'help_text' => $value === null || (is_string($value) && mb_strlen($value) <= 255) ? null : 'Teks bantuan maks 255 karakter.',
                'required' => $this->requiredError($base, $value),
                'options' => $this->optionsError($base, $value),
                'validation' => $this->validationError($base, $value),
                'default_value' => null, // checked against the final spec below
            };
            if ($error !== null) {
                $issues[] = $error;

                continue;
            }

            $spec = match ($property) {
                'label' => [...$spec, 'label' => trim($value)],
                'options' => [...$spec, 'options' => array_values(array_filter($base['options'], fn (array $o) => in_array($o['value'], $value, true)))],
                'validation' => [...$spec, 'validation' => $this->mergeValidation($spec['validation'], $value)],
                default => [...$spec, $property => $value],
            };
            $applied[] = $property;
        }

        if ($spec['default_value'] !== null && ($message = $this->values->validate($spec, $spec['default_value'], enforceRequired: false))) {
            $issues[] = in_array('default_value', $applied, true)
                ? "Nilai default tidak valid: {$message}"
                : "Nilai default generik tidak sesuai dengan override ({$message}); override juga nilai default.";
        }
        sort($applied);

        return [$spec, $issues, $applied];
    }

    /** @param array<string, mixed> $base */
    private function requiredError(array $base, mixed $value): ?string
    {
        if (! is_bool($value)) {
            return 'Nilai required harus true/false.';
        }

        return $value === false && $base['required'] ? 'Atribut wajib dari Generic Master tidak dapat dijadikan opsional.' : null;
    }

    /** @param array<string, mixed> $base */
    private function optionsError(array $base, mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count(array_unique($value)) !== count($value)) {
            return 'Override opsi harus berupa daftar nilai yang tidak kosong dan tidak ganda.';
        }
        $unknown = array_diff($value, AttributeValueValidator::optionValues($base));

        return $unknown === [] ? null : 'Override opsi hanya boleh memilih dari opsi Generic Master: '.implode(', ', $unknown).'.';
    }

    /** @param array<string, mixed> $base */
    private function validationError(array $base, mixed $value): ?string
    {
        $type = $base['data_type'];
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return 'Override validasi harus berupa objek aturan.';
        }
        $forbidden = array_diff(array_keys($value), self::narrowableKeys($type));
        if ($forbidden !== []) {
            return 'Aturan berikut tidak dapat di-override: '.implode(', ', $forbidden).'.';
        }
        [$normalized, $errors] = $this->rules->normalizeValidation($type, $value);
        if ($errors !== []) {
            return $errors[0];
        }

        $current = $base['validation'];
        foreach ($normalized as $key => $new) {
            $lower = in_array($key, ['min', 'min_length'], true);
            $limit = $current[$key] ?? match ($key) {
                'min_length' => 0,
                'max_length' => AttributeType::MAX_LENGTH[$type],
                'decimals' => AttributeType::MAX_DECIMALS,
                default => null,
            };
            if ($limit !== null && ($lower ? $new < $limit : $new > $limit)) {
                return "Aturan '{$key}' hanya boleh diperketat (batas Generic Master: {$limit}).";
            }
        }
        $merged = $this->mergeValidation($current, $normalized);
        if ((isset($merged['min'], $merged['max']) && $merged['min'] > $merged['max'])
            || (isset($merged['min_length'], $merged['max_length']) && $merged['min_length'] > $merged['max_length'])) {
            return 'Batas minimum hasil override melebihi batas maksimum.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $narrowed
     * @return array<string, mixed>
     */
    private function mergeValidation(array $current, array $narrowed): array
    {
        $merged = [...$current, ...$narrowed];
        ksort($merged);

        return $merged;
    }
}
