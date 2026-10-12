<?php

namespace App\Domain\GenericMaster\Attributes;

use Illuminate\Validation\ValidationException;

/**
 * Semantic validation of an attribute definition (generic or tenant): type-specific
 * validation rules, enum options, the default value, and which properties a tenant may
 * override. Request validation checks shapes; this class checks meaning and normalizes.
 */
final class AttributeDefinitionRules
{
    private const OPTION_VALUE = '/^[A-Za-z0-9_.-]{1,50}$/';

    public function __construct(private readonly AttributeValueValidator $values) {}

    /**
     * @param  array<string, mixed>  $data  data_type, is_required, validation, options, default_value, overridable
     * @return array{validation: array<string, mixed>, options: list<array{value: string, label: string}>|null, default_value: mixed, overridable: list<string>}
     *
     * @throws ValidationException
     */
    public function normalize(array $data, bool $withOverridable): array
    {
        $type = $data['data_type'];
        $errors = [];

        [$validation, $validationErrors] = $this->normalizeValidation($type, $data['validation'] ?? []);
        if ($validationErrors !== []) {
            $errors['validation'] = $validationErrors;
        }

        [$options, $optionErrors] = $this->normalizeOptions($type, $data['options'] ?? null);
        if ($optionErrors !== []) {
            $errors['options'] = $optionErrors;
        }

        $overridable = [];
        if ($withOverridable) {
            [$overridable, $overridableErrors] = $this->normalizeOverridable($type, $data['overridable'] ?? []);
            if ($overridableErrors !== []) {
                $errors['overridable'] = $overridableErrors;
            }
        }

        $default = $data['default_value'] ?? null;
        if ($errors === [] && $default !== null) {
            $spec = ['data_type' => $type, 'validation' => $validation, 'options' => $options];
            if ($message = $this->values->validate($spec, $default, enforceRequired: false)) {
                $errors['default_value'] = [$message];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['validation' => $validation, 'options' => $options, 'default_value' => $default, 'overridable' => $overridable];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function normalizeValidation(string $type, array $rules): array
    {
        $allowed = AttributeType::VALIDATION_KEYS[$type] ?? [];
        $errors = [];
        $out = [];

        foreach ($rules as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = "Aturan '{$key}' tidak berlaku untuk tipe {$type}.";

                continue;
            }
            $error = match ($key) {
                'min_length', 'max_length' => is_int($value) && $value >= ($key === 'max_length' ? 1 : 0) && $value <= AttributeType::MAX_LENGTH[$type]
                    ? null : "{$key} harus bilangan bulat 0–".AttributeType::MAX_LENGTH[$type].'.',
                'pattern' => is_string($value) && $value !== '' && mb_strlen($value) <= 200 && AttributeValueValidator::compilePattern($value) !== null
                    ? null : 'Pola (regex) tidak valid.',
                'decimals' => is_int($value) && $value >= 0 && $value <= AttributeType::MAX_DECIMALS
                    ? null : 'decimals harus 0–'.AttributeType::MAX_DECIMALS.'.',
                'min', 'max' => $this->boundError($type, $value, $key),
            };
            if ($error !== null) {
                $errors[] = $error;

                continue;
            }
            $out[$key] = $value;
        }

        if (isset($out['min'], $out['max']) && $out['min'] > $out['max']) {
            $errors[] = 'Nilai minimum tidak boleh lebih besar dari maksimum.';
        }
        if (isset($out['min_length'], $out['max_length']) && $out['min_length'] > $out['max_length']) {
            $errors[] = 'Panjang minimum tidak boleh lebih besar dari maksimum.';
        }
        ksort($out);

        return [$out, $errors];
    }

    /** @return array{0: list<array{value: string, label: string}>|null, 1: list<string>} */
    public function normalizeOptions(string $type, mixed $options): array
    {
        if (! AttributeType::hasOptions($type)) {
            return $options === null || $options === [] ? [null, []] : [null, ['Pilihan hanya berlaku untuk tipe enum/multi_enum.']];
        }
        if (! is_array($options) || ! array_is_list($options) || $options === [] || count($options) > AttributeType::MAX_OPTIONS) {
            return [null, ['Tipe pilihan membutuhkan 1–'.AttributeType::MAX_OPTIONS.' opsi.']];
        }

        $out = [];
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;
            $label = is_array($option) ? ($option['label'] ?? null) : null;
            if (! is_string($value) || ! preg_match(self::OPTION_VALUE, $value)) {
                return [null, ['Nilai opsi hanya boleh huruf, angka, titik, garis bawah, atau strip (maks 50).']];
            }
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                return [null, ["Label opsi '{$value}' wajib diisi (maks 100 karakter)."]];
            }
            if (isset($out[$value])) {
                return [null, ["Nilai opsi '{$value}' ganda."]];
            }
            $out[$value] = ['value' => $value, 'label' => trim($label)];
        }

        return [array_values($out), []];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    public function normalizeOverridable(string $type, mixed $properties): array
    {
        if (! is_array($properties) || ! array_is_list($properties)) {
            return [[], ['Daftar properti yang dapat di-override tidak valid.']];
        }
        $errors = [];
        foreach ($properties as $property) {
            if (! in_array($property, OverridePolicy::ALLOWLIST, true)) {
                $errors[] = "Properti '{$property}' tidak dapat di-override.";
            } elseif ($property === 'options' && ! AttributeType::hasOptions($type)) {
                $errors[] = 'Override opsi hanya untuk tipe enum/multi_enum.';
            } elseif ($property === 'validation' && OverridePolicy::narrowableKeys($type) === []) {
                $errors[] = "Tipe {$type} tidak memiliki aturan validasi yang dapat dipersempit.";
            }
        }
        $properties = array_values(array_unique($properties));
        sort($properties);

        return [$properties, $errors];
    }

    private function boundError(string $type, mixed $value, string $key): ?string
    {
        return match ($type) {
            AttributeType::INTEGER => is_int($value) ? null : "{$key} harus bilangan bulat.",
            AttributeType::DECIMAL => is_int($value) || is_float($value) ? null : "{$key} harus angka.",
            AttributeType::DATE => is_string($value) && AttributeValueValidator::isDate($value) ? null : "{$key} harus tanggal YYYY-MM-DD.",
            default => "Aturan '{$key}' tidak berlaku untuk tipe {$type}.",
        };
    }
}
