<?php

namespace App\Domain\AssetTransactions\Types;

use App\Domain\Assets\AssetScope;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
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
        'attachment' => 'Lampiran',
    ];

    /** Security limits (F-07 §7). */
    public const MAX_FIELDS = 40;

    public const MAX_OPTIONS = 50;

    /** Longest hint of a field (petunjuk pengisian, F-08j). */
    public const MAX_HELP = 200;

    private const TEXT_MAX = ['text' => 255, 'textarea' => 2000];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
        private readonly TransactionAttachments $attachments,
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
            'fields.*.settings' => ['sometimes', 'array:max_length,min,max,decimals,options,not_before_today,not_after_today,default,accept,max_mb,help'],
            // A hint for whoever fills the field in (F-08 §5 "Petunjuk field"), shown as text.
            'fields.*.settings.help' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HELP],
            'fields.*.settings.accept' => ['sometimes', 'array'],
            'fields.*.settings.accept.*' => ['string', 'distinct', Rule::in(array_keys(TransactionAttachments::KINDS))],
            'fields.*.settings.max_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
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
        $help = trim((string) ($settings['help'] ?? ''));

        return [...self::typeSettings($field, $settings, $fail), ...($help === '' ? [] : ['help' => mb_substr($help, 0, self::MAX_HELP)])];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function typeSettings(array $field, array $settings, Closure $fail): array
    {
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
            // Kinds of file accepted (none: all) and a size limit, capped by max_upload_mb on upload.
            'attachment' => array_filter([
                'accept' => array_values(array_unique((array) ($settings['accept'] ?? []))) ?: null,
                'max_mb' => isset($settings['max_mb']) ? (int) $settings['max_mb'] : null,
            ], fn ($v) => $v !== null),
            default => [],
        };
    }

    /**
     * Validate the entered values against the type's fields and return what is stored: every
     * field key, normalized (numbers as numbers, unknown keys refused).
     *
     * @param  iterable<TransactionTypeField>  $fields  the fields accepted here (a flow step may take a subset)
     * @param  array<string, mixed>  $input
     * @param  list<string>|null  $required  keys required here; null: each field's own is_required
     * @param  string|null  $runId  the run the values belong to: its attachments stay usable
     * @return array<string, mixed>
     */
    public function values(iterable $fields, array $input, string $today, ?array $required = null, ?string $runId = null): array
    {
        $rules = [];
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
            $isRequired = $required === null ? $field->is_required : in_array($field->key, $required, true);
            foreach ($this->valueRules($field, $today, $isRequired, $runId) as $path => $rule) {
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

    /**
     * After the values are saved: their attachment files belong to the run or transaction.
     *
     * @param  iterable<TransactionTypeField>  $fields
     * @param  array<string, mixed>  $values
     */
    public function claimAttachments(iterable $fields, array $values, ?string $runId, ?string $transactionNumber = null): void
    {
        $this->attachments->claim($fields, $values, $runId, $transactionNumber);
    }

    /** @return array<string, list<mixed>> path below the field ('' = the field itself) => rules */
    private function valueRules(TransactionTypeField $field, string $today, bool $isRequired, ?string $runId): array
    {
        $presence = $isRequired ? ['required'] : ['nullable'];
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
                '' => [...$presence, 'array', ...($isRequired ? ['min:1'] : [])],
                '*' => ['string', 'distinct', Rule::in(array_column($s['options'] ?? [], 'value'))],
            ],
            'boolean' => ['' => [...($isRequired ? ['present'] : ['nullable']), 'boolean']],
            'user' => ['' => [...$presence, 'uuid', $this->activeMember()]],
            'location' => ['' => [...$presence, 'uuid', $this->locationInScope()]],
            'counterparty' => [
                '' => [...$presence, 'array:name,contact'],
                'name' => [$isRequired ? 'required' : 'required_with:field_values.'.$field->key.'.contact', 'nullable', 'string', 'max:150'],
                'contact' => ['nullable', 'string', 'max:150'],
            ],
            // The id of a file uploaded for this field (TransactionAttachments).
            'attachment' => ['' => [...$presence, 'uuid', function (string $attribute, mixed $value, Closure $fail) use ($field, $runId) {
                if (! is_string($value) || ! $this->attachments->usable(strtolower($value), $field, $runId)) {
                    $fail(':attribute: file tidak ditemukan. Unggah ulang file-nya.');
                }
            }]],
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
            'user', 'location', 'attachment' => strtolower((string) $value),
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
                $fail(':attribute harus anggota aktif business unit.');
            }
        };
    }

    /**
     * Stored values as text for people (lists, detail pages, reports): option labels, member and
     * location names, Ya/Tidak, file names. Outside-party contacts are left out here; callers that
     * may show them add them.
     *
     * @param  iterable<TransactionTypeField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string|null> key => text
     */
    public static function display(iterable $fields, array $values): array
    {
        $fields = collect($fields);
        $ids = fn (string $type) => $fields->where('data_type', $type)->map(fn ($f) => $values[$f->key] ?? null)->filter(fn ($v) => is_string($v))->values()->all();
        // Reports resolve many rows: the id→name lookups are memoized per id set.
        $users = ($u = $ids('user')) === [] ? [] : self::memo('user', $u, fn () => User::query()->whereKey($u)->pluck('name', 'id')->all());
        $locations = ($l = $ids('location')) === [] ? [] : self::memo('location', $l, fn () => Location::query()->whereKey($l)->pluck('name', 'id')->all());
        $files = ($a = $ids('attachment')) === [] ? [] : self::memo('attachment', $a, fn () => TransactionAttachments::summaries($a));

        $out = [];
        foreach ($fields as $field) {
            $v = $values[$field->key] ?? null;
            $options = collect($field->settings['options'] ?? [])->pluck('label', 'value');
            $out[$field->key] = $v === null || $v === '' || $v === [] ? null : match ($field->data_type) {
                'select' => (string) ($options[$v] ?? $v),
                'multiselect' => implode(', ', array_map(fn ($x) => (string) ($options[$x] ?? $x), (array) $v)),
                'boolean' => $v ? 'Ya' : 'Tidak',
                'user' => $users[$v] ?? null,
                'location' => $locations[$v] ?? null,
                'attachment' => $files[$v]['name'] ?? null,
                'counterparty' => is_array($v) ? ($v['name'] ?? null) : null,
                'money' => 'Rp '.number_format((float) $v, 0, ',', '.'),
                'number' => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',') : (string) $v,
                default => is_scalar($v) ? (string) $v : null,
            };
        }

        return $out;
    }

    /** @var array<string, array<string, mixed>> */
    private static array $displayMemo = [];

    /**
     * @param  list<string>  $ids
     * @param  Closure(): array<string, mixed>  $loader
     * @return array<string, mixed>
     */
    private static function memo(string $kind, array $ids, Closure $loader): array
    {
        $key = $kind.'|'.implode(',', $ids);

        return self::$displayMemo[$key] ??= $loader();
    }

    /**
     * An active location the user may record into. A user with department entries may pick
     * any location: their department's assets stay within reach wherever they are (F-09 §3.3).
     */
    private function locationInScope(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $ok = is_string($value) && Location::query()->whereKey($value)->exists()
                && ($this->scope->hasDepartments() || $this->scope->allowsLocation($value));
            if (! $ok) {
                $fail(':attribute tidak ditemukan atau di luar cakupan data Anda.');
            }
        };
    }
}
