<?php

namespace App\Domain\TenantMaster\Services;

use App\Domain\GenericMaster\Attributes\AttributeDefinitionRules;
use App\Domain\GenericMaster\Attributes\AttributeType;
use App\Domain\GenericMaster\Attributes\AttributeValueValidator;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom fields of asset categories and the validation of asset values against them.
 *
 * The fields of a category are its own plus those of its ancestors (root first), so a field
 * on "Kendaraan" also applies to "Mobil" and "Motor". Users only see labels; keys are derived
 * from the label once and never change, so stored asset values stay attached to their field.
 */
final class CategoryAttributeService
{
    /** Data types offered to organizations (multi_enum is not). */
    public const TYPES = [AttributeType::STRING, AttributeType::TEXT, AttributeType::INTEGER, AttributeType::DECIMAL,
        AttributeType::BOOLEAN, AttributeType::DATE, AttributeType::ENUM];

    private const AUDIT = ['category_id', 'key', 'label', 'data_type', 'is_required', 'options', 'status', 'is_default', 'section'];

    /** Default attribute sections and their definitions. */
    public const DEFAULT_ATTRIBUTES = [
        'basic_information' => [
            ['label' => 'Name', 'data_type' => AttributeType::STRING, 'is_required' => true],
            ['label' => 'Brand', 'data_type' => AttributeType::STRING, 'is_required' => false],
            ['label' => 'Model', 'data_type' => AttributeType::STRING, 'is_required' => false],
            ['label' => 'UOM', 'data_type' => AttributeType::STRING, 'is_required' => false],
        ],
        // No "Location" here: assets already have the built-in location (Lokasi) field.
        'location_ownership' => [
            ['label' => 'Owner', 'data_type' => AttributeType::STRING, 'is_required' => false],
            ['label' => 'Responsible Person', 'data_type' => AttributeType::STRING, 'is_required' => false],
        ],
        'status_condition' => [
            ['label' => 'Operating Hours', 'data_type' => AttributeType::INTEGER, 'is_required' => false],
            ['label' => 'Condition', 'data_type' => AttributeType::ENUM, 'is_required' => false, 'options' => [
                ['value' => 'GOOD', 'label' => 'Good'],
                ['value' => 'FAIR', 'label' => 'Fair'],
                ['value' => 'POOR', 'label' => 'Poor'],
                ['value' => 'DAMAGED', 'label' => 'Damaged'],
            ]],
        ],
    ];

    public function __construct(
        private readonly MasterWriter $writer,
        private readonly AttributeDefinitionRules $rules,
        private readonly AttributeValueValidator $values,
    ) {}

    /**
     * Active fields that apply to assets of the category: ancestors' first, then its own.
     *
     * @return Collection<int, TenantCategoryAttribute>
     */
    public function effective(string $categoryId): Collection
    {
        $path = TenantAssetCategory::query()->whereKey($categoryId)->value('path');
        if ($path === null) {
            return collect();
        }
        $lineage = array_values(array_filter(explode('/', $path)));
        $depth = array_flip($lineage);

        return TenantCategoryAttribute::query()->with('category')
            ->whereIn('category_id', $lineage)
            ->where('status', MasterStatus::ACTIVE)
            ->get()
            ->sortBy([fn ($a, $b) => $depth[$a->category_id] <=> $depth[$b->category_id], ['sort_order', 'asc'], ['created_at', 'asc']])
            ->values();
    }

    /** @param array<string, mixed> $data label, data_type, is_required, options */
    public function create(TenantAssetCategory $category, array $data): TenantCategoryAttribute
    {
        if ($category->status === MasterStatus::ARCHIVED) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori yang diarsipkan tidak dapat diberi atribut.');
        }
        $label = trim($data['label']);
        $this->assertLabelAvailable($category, $label);
        $options = $this->normalizeOptions($data['data_type'], $data['options'] ?? null);

        return $this->writer->create(new TenantCategoryAttribute, [
            'category_id' => $category->id,
            'key' => $this->generateKey($label),
            'label' => $label,
            'data_type' => $data['data_type'],
            'is_required' => (bool) ($data['is_required'] ?? false),
            'options' => $options,
            'sort_order' => (int) TenantCategoryAttribute::query()->where('category_id', $category->id)->max('sort_order') + 1,
            'is_default' => false,
        ], 'category_attribute.created', self::AUDIT);
    }

    /**
     * Creates default attributes for a new category.
     * Default attributes are read-only and cannot be modified by users.
     */
    public function createDefaultAttributes(TenantAssetCategory $category): void
    {
        $sortOrder = 0;
        foreach (self::DEFAULT_ATTRIBUTES as $section => $attributes) {
            foreach ($attributes as $attr) {
                $label = trim($attr['label']);
                $options = $attr['options'] ?? null;

                $this->writer->create(new TenantCategoryAttribute, [
                    // Explicit so this also works in system context (backfill seeder).
                    'organization_id' => $category->organization_id,
                    'category_id' => $category->id,
                    'key' => $this->generateKey($label),
                    'label' => $label,
                    'data_type' => $attr['data_type'],
                    'is_required' => (bool) ($attr['is_required'] ?? false),
                    'options' => $options,
                    'sort_order' => ++$sortOrder,
                    'is_default' => true,
                    'section' => $section,
                ], 'category_attribute.created', self::AUDIT);
            }
        }
    }

    /** @param array<string, mixed> $data label, is_required, options */
    public function update(TenantCategoryAttribute $attribute, array $data): TenantCategoryAttribute
    {
        if ($attribute->status === MasterStatus::ARCHIVED) {
            throw ApiException::conflict('ALREADY_ARCHIVED', 'Atribut sudah dihapus.');
        }
        if ($attribute->is_default) {
            throw ApiException::conflict('DEFAULT_ATTRIBUTE_IMMUTABLE', 'Atribut default tidak dapat diubah.');
        }
        $changes = [];
        if (array_key_exists('label', $data)) {
            $changes['label'] = trim($data['label']);
            $this->assertLabelAvailable($attribute->category, $changes['label'], $attribute->id);
        }
        if (array_key_exists('is_required', $data)) {
            $changes['is_required'] = (bool) $data['is_required'];
        }
        if (array_key_exists('options', $data)) {
            $changes['options'] = $this->normalizeOptions($attribute->data_type, $data['options'], $attribute->options ?? []);
        }

        return $this->writer->update($attribute, $changes, 'category_attribute.updated', self::AUDIT);
    }

    /**
     * Saves the whole list of a category's own fields in one go, in the given order: rows with
     * an id update that field, rows without one create a field, own fields left out are removed
     * (archived). All or nothing; errors are keyed `attributes.{index}.{field}`.
     *
     * @param  list<array<string, mixed>>  $rows  id?, label, data_type, is_required?, options?
     * @return Collection<int, TenantCategoryAttribute> the effective fields afterwards
     *
     * @throws ValidationException
     */
    public function sync(TenantAssetCategory $category, array $rows): Collection
    {
        if ($category->status === MasterStatus::ARCHIVED) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori yang diarsipkan tidak dapat diberi atribut.');
        }
        $own = TenantCategoryAttribute::query()->where('category_id', $category->id)
            ->where('status', MasterStatus::ACTIVE)->get()->keyBy('id');

        // Validate every row first, so one save reports all problems.
        $errors = [];
        $planned = [];
        $labels = [];
        foreach ($rows as $i => $row) {
            $prefix = "attributes.{$i}";
            $existing = isset($row['id']) ? $own->get($row['id']) : null;
            if (isset($row['id']) && $existing === null) {
                $errors["{$prefix}.id"] = ['Atribut tidak ditemukan pada kategori ini.'];

                continue;
            }
            if ($existing !== null && $existing->is_default) {
                $errors["{$prefix}.id"] = ['Atribut default tidak dapat diubah.'];

                continue;
            }
            if ($existing !== null && $row['data_type'] !== $existing->data_type) {
                $errors["{$prefix}.data_type"] = ['Tipe isian tidak dapat diubah setelah atribut dibuat.'];
            }
            $label = trim($row['label']);
            $lower = mb_strtolower($label);
            if (isset($labels[$lower])) {
                $errors["{$prefix}.label"] = ['Nama atribut ganda dalam daftar ini.'];
            } elseif ($this->labelTaken($category, $label, excludeOwn: true)) {
                $errors["{$prefix}.label"] = ['Nama ini sudah dipakai atribut kategori induk atau subkategori.'];
            }
            $labels[$lower] = true;
            try {
                $options = $this->normalizeOptions($row['data_type'], $row['options'] ?? null, $existing?->options ?? []);
            } catch (ValidationException $e) {
                $errors["{$prefix}.options"] = $e->errors()['options'];

                continue;
            }
            $planned[] = [$existing, [
                'label' => $label,
                'data_type' => $row['data_type'],
                'is_required' => (bool) ($row['is_required'] ?? false),
                'options' => $options,
                'sort_order' => $i + 1,
            ]];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($category, $own, $planned) {
            $kept = [];
            foreach ($planned as [$existing, $data]) {
                if ($existing !== null) {
                    $kept[] = $existing->id;
                    unset($data['data_type']);
                    $this->writer->update($existing, $data, 'category_attribute.updated', self::AUDIT);
                } else {
                    $this->writer->create(new TenantCategoryAttribute, [
                        ...$data, 'category_id' => $category->id, 'key' => $this->generateKey($data['label']),
                    ], 'category_attribute.created', self::AUDIT);
                }
            }
            // Only archive non-default attributes
            foreach ($own->except($kept) as $removed) {
                if (!$removed->is_default) {
                    $this->archive($removed);
                }
            }
        });

        return $this->effective($category->id);
    }

    /** Removing a field archives it: it disappears from forms, its key is never reused. */
    public function archive(TenantCategoryAttribute $attribute): TenantCategoryAttribute
    {
        if ($attribute->is_default) {
            throw ApiException::conflict('DEFAULT_ATTRIBUTE_IMMUTABLE', 'Atribut default tidak dapat dihapus.');
        }
        return $this->writer->archive($attribute, 'category_attribute.archived', null);
    }

    /**
     * Merges submitted values into the current ones and validates them against the fields of
     * the category. Values of fields that do not apply (another category, removed fields) are
     * dropped; empty values are not stored.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $submitted  null = keep current values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function resolveValues(string $categoryId, array $current, ?array $submitted): array
    {
        $merged = array_merge($current, $submitted ?? []);
        $out = [];
        $errors = [];
        foreach ($this->effective($categoryId) as $attribute) {
            $value = $merged[$attribute->key] ?? null;
            if (is_string($value) && $attribute->data_type !== AttributeType::TEXT) {
                $value = trim($value);
            }
            if ($message = $this->values->validate($attribute->toSpec(), $value)) {
                $errors["attributes.{$attribute->key}"] = [$message];

                continue;
            }
            if ($value !== null && $value !== '') {
                $out[$attribute->key] = $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Options arrive as {value?, label}; a new option gets its value from its label, an
     * existing option keeps its value so assets that chose it stay valid after a rename.
     *
     * @param  list<array{value: string, label: string}>  $existing
     * @return list<array{value: string, label: string}>|null
     */
    private function normalizeOptions(string $type, mixed $options, array $existing = []): ?array
    {
        if (is_array($options) && array_is_list($options)) {
            $taken = array_column($existing, 'value');
            foreach ($options as $i => $option) {
                if (is_array($option) && empty($option['value']) && is_string($option['label'] ?? null)) {
                    $value = $this->uniqueSlug(Str::upper(Str::slug($option['label'], '_')) ?: 'OPSI', 50, fn ($v) => in_array($v, $taken, true));
                    $options[$i]['value'] = $value;
                    $taken[] = $value;
                } elseif (is_array($option) && is_string($option['value'] ?? null)) {
                    $taken[] = $option['value'];
                }
            }
        }
        [$normalized, $errors] = $this->rules->normalizeOptions($type, $options);
        if ($errors !== []) {
            throw ValidationException::withMessages(['options' => $errors]);
        }

        return $normalized;
    }

    private function generateKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'atribut';
        if (preg_match('/^\d/', $base)) {
            $base = 'a_'.$base;
        }

        return $this->uniqueSlug($base, 50, fn ($key) => in_array($key, AttributeType::RESERVED_KEYS, true)
            || TenantCategoryAttribute::query()->where('key', $key)->exists());
    }

    /** @param callable(string): bool $taken */
    private function uniqueSlug(string $base, int $max, callable $taken): string
    {
        $base = substr($base, 0, $max);
        $candidate = $base;
        for ($n = 2; $taken($candidate); $n++) {
            $candidate = substr($base, 0, $max - strlen("_{$n}"))."_{$n}";
        }

        return $candidate;
    }

    /** A label must be unique among the fields an asset of this category (or its subcategories) can have. */
    private function assertLabelAvailable(TenantAssetCategory $category, string $label, ?string $exceptId = null): void
    {
        if ($this->labelTaken($category, $label, $exceptId)) {
            throw ValidationException::withMessages(['label' => ['Atribut dengan nama ini sudah ada pada kategori ini, induknya, atau subkategorinya.']]);
        }
    }

    /** @param bool $excludeOwn ignore the category's own fields (they are being replaced as a whole) */
    private function labelTaken(TenantAssetCategory $category, string $label, ?string $exceptId = null, bool $excludeOwn = false): bool
    {
        $lineage = array_values(array_filter(explode('/', $category->path)));

        return TenantCategoryAttribute::query()
            ->where('status', MasterStatus::ACTIVE)
            ->whereRaw('lower(label) = lower(?)', [$label])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->when($excludeOwn, fn ($q) => $q->where('category_id', '!=', $category->id))
            ->where(fn ($q) => $q->whereIn('category_id', $lineage)
                ->orWhereIn('category_id', TenantAssetCategory::query()->select('id')->where('path', 'like', $category->path.'%')))
            ->exists();
    }
}
