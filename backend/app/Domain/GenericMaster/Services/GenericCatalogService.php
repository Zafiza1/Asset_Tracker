<?php

namespace App\Domain\GenericMaster\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\GenericMaster\Attributes\AttributePayload;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Masters\MaterializedPath;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Schema\SchemaResolver;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side management of the Generic Master catalog: categories, definitions, and
 * their versions. Only drafts are editable; release is one-way and freezes the version.
 * Runs only in platform context (the database rejects catalog writes from tenant context).
 */
final class GenericCatalogService
{
    private const CATEGORY_AUDIT = ['parent_id', 'code', 'name', 'description', 'sort_order', 'status'];

    private const DEFINITION_AUDIT = ['category_id', 'code', 'name', 'description', 'status'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly MasterWriter $writer,
        private readonly AttributePayload $payload,
        private readonly SchemaResolver $resolver,
    ) {}

    // ------------------------------------------------------------------ categories

    /** @param array<string, mixed> $data */
    public function createCategory(array $data): GenericAssetCategory
    {
        if (! empty($data['parent_id'])) {
            $this->activeCategory($data['parent_id']);
        }

        return $this->writer->create(new GenericAssetCategory, $data, 'generic_category.created', self::CATEGORY_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(GenericAssetCategory $category, array $data): GenericAssetCategory
    {
        if (($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertCategoryUnused($category);
        }

        return $this->writer->update($category, $data, 'generic_category.updated', self::CATEGORY_AUDIT, function (GenericAssetCategory $c) use ($data) {
            if (array_key_exists('parent_id', $data)) {
                MaterializedPath::move($c, $data['parent_id'] ? $this->activeCategory($data['parent_id']) : null);
            }
        });
    }

    public function archiveCategory(GenericAssetCategory $category, ?string $reason): GenericAssetCategory
    {
        $this->assertCategoryUnused($category);

        return $this->writer->archive($category, 'generic_category.archived', $reason);
    }

    // ------------------------------------------------------------------ definitions

    /** Creates the definition together with its first (empty) draft version. */
    public function createDefinition(array $data): GenericAssetDefinition
    {
        $this->activeCategory($data['category_id']);

        return DB::transaction(function () use ($data) {
            $definition = $this->writer->create(new GenericAssetDefinition, $data, 'generic_definition.created', self::DEFINITION_AUDIT);
            $this->newVersion($definition, 1);

            return $definition;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDefinition(GenericAssetDefinition $definition, array $data): GenericAssetDefinition
    {
        if (isset($data['category_id']) && $data['category_id'] !== $definition->category_id) {
            $this->activeCategory($data['category_id']);
            // Tenant categories are anchored to the generic category of what they contain.
            if (DB::table('tenant_asset_definitions')->where('generic_definition_id', $definition->id)->exists()) {
                throw ApiException::conflict('DEFINITION_IN_USE', 'Kategori tidak dapat diubah karena definisi sudah diadopsi organisasi.');
            }
        }

        return $this->writer->update($definition, $data, 'generic_definition.updated', self::DEFINITION_AUDIT);
    }

    public function archiveDefinition(GenericAssetDefinition $definition, ?string $reason): GenericAssetDefinition
    {
        return $this->writer->archive($definition, 'generic_definition.archived', $reason);
    }

    // ------------------------------------------------------------------ versions

    public function createDraft(GenericAssetDefinition $definition): GenericDefinitionVersion
    {
        if ($definition->status === MasterStatus::ARCHIVED) {
            throw ApiException::conflict('DEFINITION_ARCHIVED', 'Definisi yang diarsipkan tidak dapat diubah.');
        }
        if ($definition->versions()->where('status', GenericDefinitionVersion::DRAFT)->exists()) {
            throw ApiException::conflict('DRAFT_EXISTS', 'Masih ada versi draft untuk definisi ini.');
        }

        return DB::transaction(function () use ($definition) {
            $latest = $definition->versions()->orderByDesc('version_number')->lockForUpdate()->first();
            $draft = $this->newVersion($definition, ($latest?->version_number ?? 0) + 1);

            // Start from the previous structure; the previous version itself stays untouched.
            foreach ($latest?->attributeDefinitions()->get() ?? [] as $attribute) {
                $copy = new GenericAttributeDefinition;
                $copy->forceFill([...$attribute->only(GenericAttributeDefinition::COPYABLE), 'definition_version_id' => $draft->id])->save();
            }

            return $draft;
        });
    }

    /** @param array{changelog?: string|null, migration_notes?: string|null} $data */
    public function updateDraft(GenericDefinitionVersion $version, array $data): GenericDefinitionVersion
    {
        $this->assertDraft($version);

        return $this->writer->update($version, $data, 'generic_version.updated', ['changelog', 'migration_notes']);
    }

    public function discardDraft(GenericDefinitionVersion $version): void
    {
        $this->assertDraft($version);

        DB::transaction(function () use ($version) {
            $version->attributeDefinitions()->delete();
            $version->delete();
            $this->audit->record('generic_version.discarded', 'GenericDefinitionVersion', $version->id, before: [
                'definition_id' => $version->definition_id, 'version_number' => $version->version_number,
            ]);
        });
    }

    public function release(GenericDefinitionVersion $version): GenericDefinitionVersion
    {
        $this->assertDraft($version);
        $version->loadMissing('definition');
        if ($version->definition->status === MasterStatus::ARCHIVED) {
            throw ApiException::conflict('DEFINITION_ARCHIVED', 'Definisi yang diarsipkan tidak dapat dirilis.');
        }
        $missing = [];
        if (blank($version->changelog)) {
            $missing['changelog'] = ['Catatan perubahan wajib diisi sebelum rilis.'];
        }
        if ($version->version_number > 1 && blank($version->migration_notes)) {
            $missing['migration_notes'] = ['Strategi migrasi wajib diisi untuk versi lanjutan.'];
        }
        if ($missing !== []) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => $missing]);
        }

        return DB::transaction(function () use ($version) {
            $version->forceFill([
                'status' => GenericDefinitionVersion::RELEASED,
                'schema_hash' => $this->resolver->genericHash($version),
                'released_at' => now(),
                'released_by' => $this->actorId(),
            ])->save();
            $this->audit->record('generic_version.released', $version, after: [
                'definition_code' => $version->definition->code,
                'version_number' => $version->version_number,
                'schema_hash' => $version->schema_hash,
                'attributes' => $version->attributeDefinitions()->pluck('key')->all(),
            ]);

            return $version;
        });
    }

    public function deprecate(GenericDefinitionVersion $version): GenericDefinitionVersion
    {
        if ($version->status !== GenericDefinitionVersion::RELEASED) {
            throw ApiException::conflict('VERSION_NOT_RELEASED', 'Hanya versi yang sudah dirilis yang dapat di-deprecate.');
        }

        return DB::transaction(function () use ($version) {
            $version->forceFill([
                'status' => GenericDefinitionVersion::DEPRECATED,
                'deprecated_at' => now(),
                'deprecated_by' => $this->actorId(),
            ])->save();
            $this->audit->record('generic_version.deprecated', $version,
                before: ['status' => GenericDefinitionVersion::RELEASED], after: ['status' => GenericDefinitionVersion::DEPRECATED]);

            return $version;
        });
    }

    // ------------------------------------------------------------------ attributes

    /** @param array<string, mixed> $data */
    public function addAttribute(GenericDefinitionVersion $version, array $data): GenericAttributeDefinition
    {
        $this->assertDraft($version);
        $this->assertKeyFree($version, $data['key']);

        $attribute = new GenericAttributeDefinition;
        $columns = $this->payload->prepare($data, null, GenericAttributeDefinition::COPYABLE, withOverridable: true);

        return $this->writer->create($attribute, [...$columns, 'definition_version_id' => $version->id],
            'generic_attribute.created', ['definition_version_id', ...GenericAttributeDefinition::COPYABLE]);
    }

    /** @param array<string, mixed> $data */
    public function updateAttribute(GenericAttributeDefinition $attribute, array $data): GenericAttributeDefinition
    {
        $this->assertDraft($attribute->version);
        $columns = $this->payload->prepare($data, $attribute->only(GenericAttributeDefinition::COPYABLE),
            GenericAttributeDefinition::COPYABLE, withOverridable: true);

        return $this->writer->update($attribute, $columns, 'generic_attribute.updated', GenericAttributeDefinition::COPYABLE);
    }

    public function deleteAttribute(GenericAttributeDefinition $attribute): void
    {
        $this->assertDraft($attribute->version);

        DB::transaction(function () use ($attribute) {
            $attribute->delete();
            $this->audit->record('generic_attribute.deleted', 'GenericAttributeDefinition', $attribute->id,
                before: $attribute->only(['definition_version_id', 'key', 'label', 'data_type']));
        });
    }

    // ------------------------------------------------------------------ helpers

    private function newVersion(GenericAssetDefinition $definition, int $number): GenericDefinitionVersion
    {
        $version = new GenericDefinitionVersion;
        $version->forceFill([
            'definition_id' => $definition->id,
            'version_number' => $number,
            'status' => GenericDefinitionVersion::DRAFT,
            'created_by' => $this->actorId(),
        ])->save();
        $this->audit->record('generic_version.created', $version, after: ['definition_code' => $definition->code, 'version_number' => $number]);

        return $version;
    }

    private function assertDraft(GenericDefinitionVersion $version): void
    {
        if (! $version->isDraft()) {
            throw ApiException::conflict('VERSION_IMMUTABLE', 'Versi yang sudah dirilis tidak dapat diubah. Buat versi draft baru.');
        }
    }

    private function assertKeyFree(GenericDefinitionVersion $version, string $key): void
    {
        if ($version->attributeDefinitions()->where('key', $key)->exists()) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => ['key' => ['Kunci atribut sudah dipakai pada versi ini.']]]);
        }
    }

    private function activeCategory(string $id): GenericAssetCategory
    {
        $category = GenericAssetCategory::query()->find($id);
        if ($category === null || $category->status !== MasterStatus::ACTIVE) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori generik tidak ditemukan atau tidak aktif.');
        }

        return $category;
    }

    private function assertCategoryUnused(GenericAssetCategory $category): void
    {
        $inUse = GenericAssetCategory::query()->where('parent_id', $category->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists()
            || $category->definitions()->where('status', '!=', MasterStatus::ARCHIVED)->exists()
            || DB::table('tenant_asset_categories')->where('generic_category_id', $category->id)->exists();
        if ($inUse) {
            throw ApiException::conflict('CATEGORY_IN_USE', 'Kategori masih memiliki subkategori, definisi, atau dipakai organisasi.');
        }
    }

    private function actorId(): ?string
    {
        return $this->tenancy->platform()?->user->id;
    }
}
