<?php

namespace App\Domain\AssetTransactions\Types;

use App\Domain\Assets\AssetScope;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\Tenancy;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Field types of an organization's transaction types (docs/factory/features/F-07 §3.2): which
 * settings each type takes, and how an entered value is validated and stored. Settings are
 * plain limits and option lists, never expressions or patterns written by users (F-07 §7).
 */
final class TransactionFieldTypes
{
    public const TYPES = [
        'text' => 'Teks pendek',
        'textarea' => 'Teks panjang',
        'number' => 'Angka',
        'money' => 'Uang',
        'date' => 'Tanggal',
        'datetime' => 'Tanggal & waktu',
        'select' => 'Pilihan tunggal',
        'multiselect' => 'Pilihan ganda',
        'boolean' => 'Ya/Tidak',
        'user' => 'Pengguna',
        'location' => 'Lokasi',
        'counterparty' => 'Pihak luar',
    ];

    /** Security limits (F-07 §7). */
    public const MAX_FIELDS = 40;

    public const MAX_OPTIONS = 50;

    private const TEXT_MAX = ['text' => 255, 'textarea' => 2000];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
    ) {}

    /**
     * Rules for the `fields` array of a type definition request.
     *
     * @return array<string, mixed>
     */
    public static function definitionRules(): array
    {
        $types = array_keys(self::TYPES);

        return [
            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*' => ['array:key,label,data_type,settings,is_required'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.data_type' => ['required', Rule::in($types)],
            'fields.*.is_required' => ['sometimes', 'boolean'],
            'fields.*.settings' => ['sometimes', 'array:max_length,min,max,decimals,options,not_before_today,not_after_today,default'],
            'fields.*.settings.max_length' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'fields.*.settings.min' => ['sometimes', 'nullable', 'numeric'],
            'fields.*.settings.max' => ['sometimes', 'nullable', 'numeric'],
            'fields.*.settings.decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'fields.*.settings.options' => ['sometimes', 'array', 'max:'.self::MAX_OPTIONS],
            'fields.*.settings.options.*' => ['array:value,label'],
            'fields.*.settings.options.*.value' => ['required', 'string', 'max:100'],
            'fields.*.settings.options.*.label' => ['required', 'string', 'max:100'],
            'fields.*.settings.not_before_today' => ['sometimes', 'boolean'],
            'fields.*.settings.not_after_today' => ['sometimes', 'boolean'],
            'fields.*.settings.default' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Settings that fit the field type, cleaned: only the keys the type uses. Throws when a
     * type misses what it needs (options for a choice) or limits contradict each other.
     *
     * @param  array<string, mixed>  $field  one validated item of `fields`
     * @return array<string, mixed>
     */
    public static function settings(array $field, int $index): array
    {
        $settings = $field['settings'] ?? [];
        $fail = fn (string $key, string $message) => throw ValidationException::withMessages(["fields.{$index}.settings.{$key}" => $message]);

        return match ($field['data_type']) {
            'text', 'textarea' => (function () use ($settings, $field, $fail) {
                $limit = self::TEXT_MAX[$field['data_type']];
                $max = (int) ($settings['max_length'] ?? $limit);
                if ($max > $limit) {
                    $fail('max_length', "Panjang maksimal {$limit} karakter.");
                }

                return ['max_length' => $max];
            })(),
            'number', 'money' => (function () use ($settings, $field, $fail) {
                $min = $settings['min'] ?? ($field['data_type'] === 'money' ? 0 : null);
                $max = $settings['max'] ?? null;
                if ($min !== null && $max !== null && (float) $min > (float) $max) {
                    $fail('max', 'Nilai maksimal tidak boleh lebih kecil dari nilai minimal.');
                }
                if ($field['data_type'] === 'money' && (float) $min < 0) {
                    $fail('min', 'Nilai uang tidak boleh negatif.');
                }

                return array_filter([
                    'min' => $min === null ? null : (float) $min,
                    'max' => $max === null ? null : (float) $max,
                    'decimals' => $field['data_type'] === 'money' ? 2 : (int) ($settings['decimals'] ?? 0),
                ], fn ($v) => $v !== null);
            })(),
            'date' => (function () use ($settings, $fail) {
                if (($settings['not_before_today'] ?? false) && ($settings['not_after_today'] ?? false)) {
                    $fail('not_after_today', 'Pilih salah satu: tidak sebelum hari ini atau tidak setelah hari ini.');
                }

                return array_filter([
                    'not_before_today' => (bool) ($settings['not_before_today'] ?? false),
                    'not_after_today' => (bool) ($settings['not_after_today'] ?? false),
                ]);
            })(),
            'select', 'multiselect' => (function () use ($settings, $fail) {
                $options = array_values($settings['options'] ?? []);
                if ($options === []) {
                    $fail('options', 'Isi minimal satu pilihan.');
                }
                $values = array_column($options, 'value');
                if (count($values) !== count(array_unique($values))) {
                    $fail('options', 'Nilai pilihan tidak boleh kembar.');
                }

                return ['options' => array_map(fn (array $o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']], $options)];
            })(),
            'boolean' => array_filter(['default' => $settings['default'] ?? null], fn ($v) => $v !== null),
            default => [],
        };
    }

    /**
     * Validate the entered values against the type's fields and return what is stored: every
     * field key, normalized (numbers as numbers, unknown keys refused).
     *
     * @param  iterable<TransactionTypeField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function values(iterable $fields, array $input, string $today): array
    {
        $rules = [];
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
            foreach ($this->valueRules($field, $today) as $path => $rule) {
                $rules[$path === '' ? "field_values.{$field->key}" : "field_values.{$field->key}.{$path}"] = $rule;
            }
        }

        $unknown = array_diff(array_keys($input), array_keys($byKey));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['field_values' => 'Isian tidak dikenal untuk jenis ini: '.implode(', ', $unknown).'.']);
        }

        $attributes = [];
        foreach ($byKey as $key => $field) {
            $attributes["field_values.{$key}"] = $field->label;
        }
        Validator::make(['field_values' => $input], $rules, [], $attributes)->validate();

        $stored = [];
        foreach ($byKey as $key => $field) {
            $stored[$key] = $this->normalize($field, $input[$key] ?? null);
        }

        return $stored;
    }

    /** @return array<string, list<mixed>> path below the field ('' = the field itself) => rules */
    private function valueRules(TransactionTypeField $field, string $today): array
    {
        $presence = $field->is_required ? ['required'] : ['nullable'];
        $s = $field->settings ?? [];

        return match ($field->data_type) {
            'text', 'textarea' => ['' => [...$presence, 'string', 'max:'.($s['max_length'] ?? self::TEXT_MAX[$field->data_type])]],
            'number', 'money' => ['' => [...$presence, 'numeric', 'decimal:0,'.($s['decimals'] ?? 0),
                ...(isset($s['min']) ? ['min:'.$s['min']] : []), ...(isset($s['max']) ? ['max:'.$s['max']] : [])]],
            'date' => ['' => [...$presence, 'date_format:Y-m-d',
                ...(($s['not_before_today'] ?? false) ? ['after_or_equal:'.$today] : []),
                ...(($s['not_after_today'] ?? false) ? ['before_or_equal:'.$today] : [])]],
            'datetime' => ['' => [...$presence, 'date']],
            'select' => ['' => [...$presence, 'string', Rule::in(array_column($s['options'] ?? [], 'value'))]],
            'multiselect' => [
                '' => [...$presence, 'array', ...($field->is_required ? ['min:1'] : [])],
                '*' => ['string', 'distinct', Rule::in(array_column($s['options'] ?? [], 'value'))],
            ],
            'boolean' => ['' => [...($field->is_required ? ['present'] : ['nullable']), 'boolean']],
            'user' => ['' => [...$presence, 'uuid', $this->activeMember()]],
            'location' => ['' => [...$presence, 'uuid', $this->locationInScope()]],
            'counterparty' => [
                '' => [...$presence, 'array:name,contact'],
                'name' => [$field->is_required ? 'required' : 'required_with:field_values.'.$field->key.'.contact', 'nullable', 'string', 'max:150'],
                'contact' => ['nullable', 'string', 'max:150'],
            ],
        };
    }

    private function normalize(TransactionTypeField $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $field->data_type === 'boolean' && isset($field->settings['default']) ? (bool) $field->settings['default'] : null;
        }

        return match ($field->data_type) {
            'number' => ($field->settings['decimals'] ?? 0) === 0 ? (int) $value : (float) $value,
            'money' => round((float) $value, 2),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multiselect' => array_values($value),
            'user', 'location' => strtolower((string) $value),
            'counterparty' => ['name' => $value['name'] ?? null, 'contact' => $value['contact'] ?? null],
            default => $value,
        };
    }

    /** An active member of this organization. */
    private function activeMember(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $ok = is_string($value) && OrganizationMember::query()->where('organization_id', $this->tenancy->organizationId())
                ->where('user_id', $value)->where('status', User::STATUS_ACTIVE)->exists();
            if (! $ok) {
                $fail(':attribute harus anggota aktif organisasi.');
            }
        };
    }

    /** An active location the user may record into. */
    private function locationInScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $ok = is_string($value) && Location::query()->whereKey($value)->exists() && $this->scope->allowsLocation($value);
            if (! $ok) {
                $fail(':attribute tidak ditemukan atau di luar cakupan data Anda.');
            }
        };
    }
}
