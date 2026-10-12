<?php

namespace App\Domain\TenantMaster\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\GenericMaster\Attributes\AttributePayload;
use App\Domain\GenericMaster\Attributes\OverridePolicy;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeOverride;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use App\Domain\TenantMaster\Schema\ResolvedSchema;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Support\Facades\DB;

/**
 * Tenant Custom Master: adopting generic definitions and evolving them in versions.
 *
 * - Adoption creates a tenant definition plus a draft v1 pinned to a released generic version.
 * - Drafts hold tenant attributes and overrides; nothing here ever writes a generic record.
 * - Release computes the effective schema, rejects it when invalid, then freezes it with
 *   its hash. A newer generic release changes nothing until a new tenant version is
 *   explicitly based on it.
 */
final class TenantDefinitionService
{
    private const DEFINITION_AUDIT = ['generic_definition_id', 'tenant_category_id', 'code', 'name', 'description', 'status'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly MasterWriter $writer,
        private readonly AttributePayload $payload,
        private readonly OverridePolicy $overridePolicy,
        private readonly SchemaResolver $resolver,
    ) {}

    // ------------------------------------------------------------------ definitions

    /**
     * @param  array<string, mixed>  $data  generic_definition_id, generic_version_id?, code, name, description?, tenant_category_id?
     */
    public function adopt(array $data): TenantAssetDefinition
    {
        $generic = GenericAssetDefinition::query()->with('category')->find($data['generic_definition_id']);
        if ($generic === null || $generic->status !== MasterStatus::ACTIVE) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Definisi Generic Master tidak ditemukan atau tidak aktif.');
        }
        $genericVersion = $this->releasedGenericVersion($generic, $data['generic_version_id'] ?? null);
        if (! empty($data['tenant_category_id'])) {
            $this->assertCategoryFits($data['tenant_category_id'], $generic);
        }

        return DB::transaction(function () use ($data, $generic, $genericVersion) {
            $definition = $this->writer->create(new TenantAssetDefinition, [
                'generic_definition_id' => $generic->id,
                'tenant_category_id' => $data['tenant_category_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => MasterStatus::ACTIVE,
                'created_by' => $this->tenancy->tenant()->userId(),
            ], 'tenant_definition.adopted', self::DEFINITION_AUDIT);
            $this->newVersion($definition, 1, $genericVersion);

            return $definition;
        });
    }

    /** @param array<string, mixed> $data name, description, tenant_category_id, status */
    public function update(TenantAssetDefinition $definition, array $data): TenantAssetDefinition
    {
        if (! empty($data['tenant_category_id']) && $data['tenant_category_id'] !== $definition->tenant_category_id) {
            $this->assertCategoryFits($data['tenant_category_id'], $definition->genericDefinition()->with('category')->firstOrFail());
        }

        return $this->writer->update($definition, $data, 'tenant_definition.updated', self::DEFINITION_AUDIT);
    }

    public function archive(TenantAssetDefinition $definition, ?string $reason): TenantAssetDefinition
    {
        return $this->writer->archive($definition, 'tenant_definition.archived', $reason);
    }

    public function restore(TenantAssetDefinition $definition): TenantAssetDefinition
    {
        return $this->writer->restore($definition, 'tenant_definition.restored');
    }

    // ------------------------------------------------------------------ versions

    /**
     * New draft based on the latest version (its tenant attributes and overrides are copied).
     * The generic version stays the same unless another released one is chosen explicitly.
     */
    public function createDraft(TenantAssetDefinition $definition, ?string $genericVersionId): TenantDefinitionVersion
    {
        $this->assertDefinitionEditable($definition);
        if ($definition->versions()->where('status', TenantDefinitionVersion::DRAFT)->exists()) {
            throw ApiException::conflict('DRAFT_EXISTS', 'Masih ada versi draft untuk definisi ini.');
        }

        return DB::transaction(function () use ($definition, $genericVersionId) {
            $latest = $definition->versions()->orderByDesc('version_number')->lockForUpdate()->first();
            $generic = $definition->genericDefinition()->firstOrFail();
            $genericVersion = $genericVersionId !== null || $latest === null || $latest->genericVersion->status !== GenericDefinitionVersion::RELEASED
                ? $this->releasedGenericVersion($generic, $genericVersionId)
                : $latest->genericVersion;

            $draft = $this->newVersion($definition, ($latest?->version_number ?? 0) + 1, $genericVersion);
            if ($latest !== null) {
                foreach ($latest->tenantAttributes()->get() as $attribute) {
                    $copy = new TenantAttributeDefinition;
                    $copy->forceFill([...$attribute->only(TenantAttributeDefinition::COPYABLE), 'tenant_definition_version_id' => $draft->id])->save();
                }
                foreach ($latest->overrides()->get() as $override) {
                    $copy = new TenantAttributeOverride;
                    $copy->forceFill([...$override->only(['generic_attribute_key', 'overrides']), 'tenant_definition_version_id' => $draft->id])->save();
                }
            }

            return $draft;
        });
    }

    /** @param array{changelog?: string|null, generic_definition_version_id?: string} $data */
    public function updateDraft(TenantDefinitionVersion $version, array $data): TenantDefinitionVersion
    {
        $this->assertDraft($version);
        if (isset($data['generic_definition_version_id']) && $data['generic_definition_version_id'] !== $version->generic_definition_version_id) {
            $generic = $version->definition->genericDefinition()->firstOrFail();
            $data['generic_definition_version_id'] = $this->releasedGenericVersion($generic, $data['generic_definition_version_id'])->id;
        }

        return $this->writer->update($version, $data, 'tenant_definition_version.updated', ['changelog', 'generic_definition_version_id']);
    }

    public function discardDraft(TenantDefinitionVersion $version): void
    {
        $this->assertDraft($version);

        DB::transaction(function () use ($version) {
            $version->overrides()->delete();
            $version->tenantAttributes()->delete();
            $version->delete();
            $this->audit->record('tenant_definition_version.discarded', 'TenantDefinitionVersion', $version->id, before: [
                'tenant_definition_id' => $version->tenant_definition_id, 'version_number' => $version->version_number,
            ]);
        });
    }

    public function preview(TenantDefinitionVersion $version): ResolvedSchema
    {
        return $this->resolver->resolve($version);
    }

    public function release(TenantDefinitionVersion $version): TenantDefinitionVersion
    {
        $this->assertDraft($version);
        $this->assertDefinitionEditable($version->definition);
        if ($version->version_number > 1 && blank($version->changelog)) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', [
                'fields' => ['changelog' => ['Catatan perubahan wajib diisi untuk versi lanjutan.']],
            ]);
        }

        return DB::transaction(function () use ($version) {
            // Serialize with concurrent edits of the same draft.
            $version = TenantDefinitionVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            $resolved = $this->resolver->resolve($version);
            if (! $resolved->isValid()) {
                throw ApiException::unprocessable('SCHEMA_INVALID', 'Definisi belum dapat dirilis karena skema tidak valid.', ['issues' => $resolved->issues]);
            }

            $version->forceFill([
                'status' => TenantDefinitionVersion::RELEASED,
                'effective_schema' => $resolved->schema,
                'schema_hash' => $resolved->hash,
                'released_at' => now(),
                'released_by' => $this->tenancy->tenant()->userId(),
            ])->save();
            $this->audit->record('tenant_definition_version.released', $version, after: [
                'tenant_definition_id' => $version->tenant_definition_id,
                'version_number' => $version->version_number,
                'generic_definition_version_id' => $version->generic_definition_version_id,
                'schema_hash' => $resolved->hash,
            ]);

            return $version;
        });
    }

    public function deprecate(TenantDefinitionVersion $version): TenantDefinitionVersion
    {
        if ($version->status !== TenantDefinitionVersion::RELEASED) {
            throw ApiException::conflict('VERSION_NOT_RELEASED', 'Hanya versi yang sudah dirilis yang dapat di-deprecate.');
        }

        return DB::transaction(function () use ($version) {
            $version->forceFill([
                'status' => TenantDefinitionVersion::DEPRECATED,
                'deprecated_at' => now(),
                'deprecated_by' => $this->tenancy->tenant()->userId(),
            ])->save();
            $this->audit->record('tenant_definition_version.deprecated', $version,
                before: ['status' => TenantDefinitionVersion::RELEASED], after: ['status' => TenantDefinitionVersion::DEPRECATED]);

            return $version;
        });
    }

    // ------------------------------------------------------------------ tenant attributes

    /** @param array<string, mixed> $data */
    public function addAttribute(TenantDefinitionVersion $version, array $data): TenantAttributeDefinition
    {
        $this->assertDraft($version);
        $this->assertTenantKeyFree($version, $data['key']);
        $columns = $this->payload->prepare($data, null, TenantAttributeDefinition::COPYABLE, withOverridable: false);

        return $this->writer->create(new TenantAttributeDefinition, [...$columns, 'tenant_definition_version_id' => $version->id],
            'tenant_attribute.created', ['tenant_definition_version_id', ...TenantAttributeDefinition::COPYABLE]);
    }

    /** @param array<string, mixed> $data */
    public function updateAttribute(TenantAttributeDefinition $attribute, array $data): TenantAttributeDefinition
    {
        $this->assertDraft($this->versionOf($attribute));
        $columns = $this->payload->prepare($data, $attribute->only(TenantAttributeDefinition::COPYABLE),
            TenantAttributeDefinition::COPYABLE, withOverridable: false);

        return $this->writer->update($attribute, $columns, 'tenant_attribute.updated', TenantAttributeDefinition::COPYABLE);
    }

    public function deleteAttribute(TenantAttributeDefinition $attribute): void
    {
        $this->assertDraft($this->versionOf($attribute));

        DB::transaction(function () use ($attribute) {
            $attribute->delete();
            $this->audit->record('tenant_attribute.deleted', 'TenantAttributeDefinition', $attribute->id,
                before: $attribute->only(['tenant_definition_version_id', 'key', 'label', 'data_type']));
        });
    }

    // ------------------------------------------------------------------ overrides

    /**
     * Replaces all overrides of a draft. Each must target an attribute of the pinned generic
     * version and pass the override policy; nothing is saved when any entry is invalid.
     *
     * @param  list<array{generic_attribute_key: string, overrides: array<string, mixed>}>  $entries
     */
    public function syncOverrides(TenantDefinitionVersion $version, array $entries): TenantDefinitionVersion
    {
        $this->assertDraft($version);
        $generic = collect($this->resolver->genericAttributes($version->genericVersion))->keyBy('key');

        $issues = [];
        $seen = [];
        foreach ($entries as $entry) {
            $key = $entry['generic_attribute_key'];
            if (isset($seen[$key])) {
                $issues[] = "Override untuk '{$key}' ganda.";

                continue;
            }
            $seen[$key] = true;
            if (! $generic->has($key)) {
                $issues[] = "Atribut '{$key}' tidak ada pada versi Generic Master yang dipakai.";

                continue;
            }
            [, $entryIssues] = $this->overridePolicy->apply($generic[$key], $entry['overrides']);
            foreach ($entryIssues as $issue) {
                $issues[] = "{$generic[$key]['label']} ({$key}): {$issue}";
            }
        }
        if ($issues !== []) {
            throw ApiException::unprocessable('INVALID_OVERRIDE', 'Override tidak sesuai aturan Generic Master.', ['issues' => $issues]);
        }

        return DB::transaction(function () use ($version, $entries) {
            $before = $this->overrideSnapshot($version);
            $version->overrides()->delete();
            foreach ($entries as $entry) {
                if ($entry['overrides'] === []) {
                    continue;
                }
                $override = new TenantAttributeOverride;
                $override->forceFill([
                    'tenant_definition_version_id' => $version->id,
                    'generic_attribute_key' => $entry['generic_attribute_key'],
                    'overrides' => $entry['overrides'],
                ])->save();
            }
            $this->audit->record('tenant_overrides.updated', $version, before: ['overrides' => $before], after: ['overrides' => $this->overrideSnapshot($version)]);

            return $version;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function newVersion(TenantAssetDefinition $definition, int $number, GenericDefinitionVersion $genericVersion): TenantDefinitionVersion
    {
        $version = new TenantDefinitionVersion;
        $version->forceFill([
            'tenant_definition_id' => $definition->id,
            'generic_definition_version_id' => $genericVersion->id,
            'version_number' => $number,
            'status' => TenantDefinitionVersion::DRAFT,
            'created_by' => $this->tenancy->tenant()->userId(),
        ])->save();
        $this->audit->record('tenant_definition_version.created', $version, after: [
            'tenant_definition_id' => $definition->id,
            'version_number' => $number,
            'generic_definition_version_id' => $genericVersion->id,
        ]);

        return $version;
    }

    /** A released, non-deprecated version of the given generic definition (default: the latest). */
    private function releasedGenericVersion(GenericAssetDefinition $generic, ?string $versionId): GenericDefinitionVersion
    {
        $query = $generic->versions()->where('status', GenericDefinitionVersion::RELEASED);
        $version = $versionId !== null ? $query->whereKey($versionId)->first() : $query->orderByDesc('version_number')->first();

        return $version ?? throw ApiException::unprocessable('INVALID_GENERIC_VERSION',
            'Versi Generic Master tidak ditemukan, belum dirilis, atau sudah deprecated.');
    }

    private function assertCategoryFits(string $categoryId, GenericAssetDefinition $generic): void
    {
        $category = TenantAssetCategory::query()->with('genericCategory')->find($categoryId);
        if ($category === null || $category->status !== MasterStatus::ACTIVE) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori tidak ditemukan atau tidak aktif.');
        }
        if (! $category->genericCategory->contains($generic->category)) {
            throw ApiException::unprocessable('CATEGORY_MISMATCH',
                "Kategori “{$category->name}” berinduk pada kategori generik yang tidak mencakup definisi ini.");
        }
    }

    private function assertDraft(TenantDefinitionVersion $version): void
    {
        if (! $version->isDraft()) {
            throw ApiException::conflict('VERSION_IMMUTABLE', 'Versi yang sudah dirilis tidak dapat diubah. Buat versi draft baru.');
        }
    }

    private function assertDefinitionEditable(TenantAssetDefinition $definition): void
    {
        if ($definition->status === MasterStatus::ARCHIVED) {
            throw ApiException::conflict('DEFINITION_ARCHIVED', 'Definisi yang diarsipkan tidak dapat diubah.');
        }
    }

    private function assertTenantKeyFree(TenantDefinitionVersion $version, string $key): void
    {
        $taken = $version->tenantAttributes()->where('key', $key)->exists()
            || $version->genericVersion->attributeDefinitions()->where('key', $key)->exists();
        if ($taken) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', [
                'fields' => ['key' => ['Kunci atribut sudah dipakai pada definisi ini (termasuk atribut Generic Master).']],
            ]);
        }
    }

    private function versionOf(TenantAttributeDefinition $attribute): TenantDefinitionVersion
    {
        return TenantDefinitionVersion::query()->findOrFail($attribute->tenant_definition_version_id);
    }

    /** @return array<string, array<string, mixed>> */
    private function overrideSnapshot(TenantDefinitionVersion $version): array
    {
        return $version->overrides()->get()->mapWithKeys(fn (TenantAttributeOverride $o) => [$o->generic_attribute_key => $o->overrides])->all();
    }
}
