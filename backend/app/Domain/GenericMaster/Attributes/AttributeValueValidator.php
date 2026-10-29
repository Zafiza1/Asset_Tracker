<?php

namespace App\Domain\GenericMaster\Attributes;

/**
 * Validates one value against an attribute spec (as found in an effective schema).
 * Used for default values now and for asset attribute values from Phase 4. The frontend
 * mirrors these rules from the same effective schema; this class is the authority.
 *
 * Spec keys used: data_type, required, validation, options.
 */
final class AttributeValueValidator
{
    /**
     * @param  array<string, mixed>  $spec
     * @return string|null error message, or null when the value is valid
     */
    public function validate(array $spec, mixed $value, bool $enforceRequired = true): ?string
    {
        $type = $spec['data_type'];
        $rules = $spec['validation'] ?? [];

        if ($value === null || $value === '' || $value === []) {
            return $enforceRequired && ($spec['required'] ?? false) ? 'Wajib diisi.' : null;
        }

        return match ($type) {
            AttributeType::STRING, AttributeType::TEXT => $this->text($type, $value, $rules),
            AttributeType::INTEGER => $this->integer($value, $rules),
            AttributeType::DECIMAL => $this->decimal($value, $rules),
            AttributeType::BOOLEAN => is_bool($value) ? null : 'Harus bernilai ya/tidak.',
            AttributeType::DATE => $this->date($value, $rules),
            AttributeType::ENUM => (is_string($value) && in_array($value, self::optionValues($spec), true)) ? null : 'Pilihan tidak valid.',
            AttributeType::MULTI_ENUM => $this->multiEnum($value, $spec),
            default => 'Tipe data tidak dikenal.',
        };
    }

    /** @param array<string, mixed> $spec @return list<string> */
    public static function optionValues(array $spec): array
    {
        return array_values(array_map(fn (array $o) => (string) $o['value'], $spec['options'] ?? []));
    }

    /** Compiles a definition-supplied pattern safely; null when it is not a valid expression. */
    public static function compilePattern(string $pattern): ?string
    {
        $regex = '~'.str_replace('~', '\~', $pattern).'~u';

        return @preg_match($regex, '') === false ? null : $regex;
    }

    /** @param array<string, mixed> $rules */
    private function text(string $type, mixed $value, array $rules): ?string
    {
        if (! is_string($value)) {
            return 'Harus berupa teks.';
        }
        $length = mb_strlen($value);
        $max = $rules['max_length'] ?? AttributeType::MAX_LENGTH[$type];
        if ($length > $max) {
            return "Maksimal {$max} karakter.";
        }
        if (isset($rules['min_length']) && $length < $rules['min_length']) {
            return "Minimal {$rules['min_length']} karakter.";
        }
        if (isset($rules['pattern'])) {
            $regex = self::compilePattern($rules['pattern']);
            // A failing match (including backtrack-limit errors) counts as invalid input.
            if ($regex === null || preg_match($regex, $value) !== 1) {
                return 'Format tidak sesuai.';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rules */
    private function integer(mixed $value, array $rules): ?string
    {
        if (! is_int($value)) {
            return 'Harus berupa bilangan bulat.';
        }

        return $this->range($value, $rules);
    }

    /** @param array<string, mixed> $rules */
    private function decimal(mixed $value, array $rules): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value))) {
            return 'Harus berupa angka.';
        }
        $decimals = $rules['decimals'] ?? AttributeType::MAX_DECIMALS;
        $text = is_string($value) ? $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        $scale = str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : 0;
        if ($scale > $decimals) {
            return "Maksimal {$decimals} angka di belakang koma.";
        }

        return $this->range((float) $value, $rules);
    }

    /** @param array<string, mixed> $rules */
    private function date(mixed $value, array $rules): ?string
    {
        if (! is_string($value) || ! self::isDate($value)) {
            return 'Format tanggal harus YYYY-MM-DD.';
        }

        return $this->range($value, $rules);
    }

    /** @param array<string, mixed> $spec */
    private function multiEnum(mixed $value, array $spec): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'Harus berupa daftar pilihan.';
        }
        $allowed = self::optionValues($spec);
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return 'Pilihan tidak valid.';
            }
        }

        return count(array_unique($value)) === count($value) ? null : 'Pilihan tidak boleh ganda.';
    }

    /** @param array<string, mixed> $rules */
    private function range(int|float|string $value, array $rules): ?string
    {
        if (isset($rules['min']) && $value < $rules['min']) {
            return "Minimal {$rules['min']}.";
        }
        if (isset($rules['max']) && $value > $rules['max']) {
            return "Maksimal {$rules['max']}.";
        }

        return null;
    }

    public static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
