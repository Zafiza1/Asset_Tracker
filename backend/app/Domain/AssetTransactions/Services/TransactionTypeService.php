<?php

namespace App\Domain\AssetTransactions\Services;

use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Flows\FlowVersionService;
use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing an organization's transaction types and their fields (F-07a).
 *
 * Rules: the code is the number prefix, so it may not clash with a system prefix and stays fixed
 * once the type has transactions; a field's key and data type never change (stored values keep
 * their meaning); a type with transactions is deactivated, never deleted.
 */
final class TransactionTypeService
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly FlowVersionService $flows,
    ) {}

    /**
     * A new draft organization type copied from $source (F-08 "Salin jenis", D-40): its fields,
     * categories, asset rules and flow (the published version, else the draft) become the copy's
     * own and stay unlinked. A copy of a system type keeps its core action (core_type), so the
     * core rules still apply; without a flow it starts from Mulai → Aksi inti → Selesai.
     */
    public function duplicate(TransactionTypeDefinition $source, string $code, string $name): TransactionTypeDefinition
    {
        return DB::transaction(function () use ($source, $code, $name) {
            $source->loadMissing(['fields', 'categories:id']);
            $copy = new TransactionTypeDefinition;
            $copy->fill([
                'name' => $name, 'description' => $source->description, 'icon' => $source->icon,
                'multi_asset' => $source->multi_asset, 'locks_assets' => $source->locks_assets,
            ]);
            $copy->forceFill([
                'code' => $this->code($code), 'status' => TransactionTypeDefinition::DRAFT,
                'core_type' => $source->coreType(), 'created_by' => $this->tenancy->tenant()->userId(),
            ])->save();
            $this->syncCategories($copy, $source->categories->pluck('id')->all());
            $this->syncFields($copy, $source->fields->map(fn (TransactionTypeField $f) => [
                'key' => $f->key, 'label' => $f->label, 'data_type' => $f->data_type, 'settings' => $f->settings ?? [], 'is_required' => $f->is_required,
            ])->all());

            $graph = TransactionFlowVersion::query()->whereKey($source->active_flow_version_id)->value('graph')
                ?? $this->flows->draft($source)?->graph
                ?? ($source->coreType() !== null ? FlowGraph::coreStarter('Catat '.mb_strtolower($source->name)) : null);
            if ($graph !== null) {
                $this->flows->saveDraft($copy, is_string($graph) ? json_decode($graph, true) : $graph, "Disalin dari {$source->name} ({$source->code}).");
            }
            $this->audit->record('transaction_type.duplicated', $copy, after: [...$this->snapshot($copy), 'source' => $source->code]);

            return $copy;
        });
    }

    /** @param array<string, mixed> $data validated (see TransactionTypeController::rules) */
    public function create(array $data): TransactionTypeDefinition
    {
        return DB::transaction(function () use ($data) {
            $code = $this->code($data['code']);
            $definition = new TransactionTypeDefinition;
            $definition->fill([...$this->attributes($data), 'code' => $code, 'status' => $data['status'] ?? TransactionTypeDefinition::DRAFT]);
            $definition->forceFill(['created_by' => $this->tenancy->tenant()->userId()])->save();

            $this->syncCategories($definition, $data['category_ids'] ?? []);
            $this->syncFields($definition, $data['fields']);
            $this->audit->record('transaction_type.created', $definition, after: $this->snapshot($definition));

            return $definition;
        });
    }

    /** @param array<string, mixed> $data validated; keys left out keep their value */
    public function update(TransactionTypeDefinition $definition, array $data): TransactionTypeDefinition
    {
        return DB::transaction(function () use ($definition, $data) {
            $definition = TransactionTypeDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            // A system type takes the organization's own fields (F-07f-2); the rest stays built-in.
            if ($definition->isSystem() && array_diff(array_keys($data), ['fields']) !== []) {
                self::assertNotSystem($definition);
            }
            $before = $this->snapshot($definition);

            if (isset($data['code']) && strtoupper($data['code']) !== $definition->code) {
                if ($definition->transactions()->exists()) {
                    throw ApiException::conflict('TYPE_CODE_LOCKED', 'Kode tidak dapat diubah karena jenis ini sudah dipakai transaksi.');
                }
                $definition->code = $this->code($data['code'], $definition->id);
            }
            $definition->fill($this->attributes($data, $definition));
            if (isset($data['status'])) {
                $definition->status = $data['status'];
            }
            $definition->save();

            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($definition, $data['category_ids'] ?? []);
            }
            if (array_key_exists('fields', $data)) {
                $this->syncFields($definition, $data['fields']);
            }
            $this->audit->record('transaction_type.updated', $definition, before: $before, after: $this->snapshot($definition));

            return $definition;
        });
    }

    public function delete(TransactionTypeDefinition $definition): void
    {
        DB::transaction(function () use ($definition) {
            self::assertNotSystem($definition);
            if ($definition->transactions()->exists()) {
                throw ApiException::conflict('TYPE_IN_USE', 'Jenis ini sudah dipakai transaksi. Nonaktifkan jenisnya bila tidak dipakai lagi.');
            }
            if (TransactionTypeDefinition::query()->where('closes_type_id', $definition->id)->exists()) {
                throw ApiException::conflict('TYPE_IN_USE', 'Jenis ini ditutup oleh jenis lain. Lepaskan pasangannya terlebih dahulu.');
            }
            $before = $this->snapshot($definition);
            $definition->delete();
            $this->audit->record('transaction_type.deleted', $definition, before: $before);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?TransactionTypeDefinition $current = null): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'description', 'icon', 'multi_asset', 'locks_assets', 'closes_type_id']));
        if (! empty($attributes['closes_type_id'])) {
            if ($attributes['closes_type_id'] === $current?->id || ! TransactionTypeDefinition::query()->organizationOwn()->whereKey($attributes['closes_type_id'])->exists()) {
                throw ValidationException::withMessages(['closes_type_id' => 'Jenis yang ditutup tidak ditemukan.']);
            }
        }

        return $attributes;
    }

    /**
     * System types (F-07f) keep their name, code, rules and pairing, and are never deleted; the
     * organization may add fields (update with `fields` only) and a flow around the core action.
     */
    public static function assertNotSystem(TransactionTypeDefinition $definition): void
    {
        if ($definition->isSystem()) {
            throw ApiException::conflict('SYSTEM_TYPE_READONLY', "Jenis sistem {$definition->name} memakai aturan bawaan aplikasi. Yang dapat diubah hanya field tambahan dan alurnya; jenisnya tidak dapat dihapus.");
        }
    }

    /** Upper-case, not a system prefix, not used by another type of the organization. */
    private function code(string $code, ?string $ignoreId = null): string
    {
        $code = strtoupper($code);
        if (in_array($code, TransactionType::PREFIXES, true)) {
            throw ValidationException::withMessages(['code' => "Kode {$code} dipakai jenis sistem. Pilih kode lain."]);
        }
        $taken = TransactionTypeDefinition::query()->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "Kode {$code} sudah dipakai jenis lain."]);
        }

        return $code;
    }

    /** @param list<string> $categoryIds */
    private function syncCategories(TransactionTypeDefinition $definition, array $categoryIds): void
    {
        $categoryIds = array_values(array_unique(array_map('strtolower', $categoryIds)));
        if (TenantAssetCategory::query()->whereIn('id', $categoryIds)->count() !== count($categoryIds)) {
            throw ValidationException::withMessages(['category_ids' => 'Kategori tidak ditemukan.']);
        }
        $orgId = $this->tenancy->organizationId();
        $definition->categories()->sync(array_fill_keys($categoryIds, ['organization_id' => $orgId]));
    }

    /**
     * Fields in the given order. Existing keys are updated (their data type stays), new keys are
     * added, keys left out are removed: values already stored in transactions stay there.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function syncFields(TransactionTypeDefinition $definition, array $fields): void
    {
        $existing = $definition->fields()->get()->keyBy('key');
        $keep = [];
        foreach (array_values($fields) as $index => $field) {
            $values = [
                'label' => $field['label'],
                'settings' => TransactionFieldTypes::settings($field, $index),
                'is_required' => (bool) ($field['is_required'] ?? false),
                'sort_order' => $index,
            ];
            /** @var TransactionTypeField|null $current */
            $current = $existing[$field['key']] ?? null;
            if ($current !== null && $current->data_type !== $field['data_type']) {
                throw ValidationException::withMessages(["fields.{$index}.data_type" => 'Tipe field yang sudah ada tidak dapat diubah. Hapus field lalu buat field baru dengan kunci lain.']);
            }
            if ($current !== null) {
                $current->fill($values)->save();
            } else {
                $created = new TransactionTypeField(['key' => $field['key'], 'data_type' => $field['data_type'], ...$values]);
                $created->forceFill(['type_definition_id' => $definition->id])->save();
            }
            $keep[] = $field['key'];
        }
        $definition->fields()->whereNotIn('key', $keep)->delete();
    }

    /** @return array<string, mixed> */
    private function snapshot(TransactionTypeDefinition $definition): array
    {
        return [
            ...$definition->only(['code', 'name', 'status', 'multi_asset', 'locks_assets', 'closes_type_id']),
            'category_ids' => $definition->categories()->pluck('tenant_asset_categories.id')->all(),
            'fields' => $definition->fields()->get(['key', 'label', 'data_type', 'is_required'])->toArray(),
        ];
    }
}
