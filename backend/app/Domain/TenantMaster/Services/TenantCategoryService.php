<?php

namespace App\Domain\TenantMaster\Services;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterUsage;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Masters\MaterializedPath;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Support\Facades\DB;

/**
 * Organization categories. Every category is anchored to one generic category (internal
 * reference data, not shown to users): a root category without an explicit anchor gets the
 * general one (UMUM), subcategories inherit the anchor of their parent. The anchor and code
 * never change (also enforced by a DB trigger).
 */
final class TenantCategoryService
{
    private const AUDIT = ['parent_id', 'generic_category_id', 'code', 'name', 'description', 'status'];

    /** Generic anchor of root categories created without one. */
    public const DEFAULT_GENERIC_CATEGORY = 'UMUM';

    public function __construct(
        private readonly MasterWriter $writer,
        private readonly MasterUsage $usage,
        private readonly CategoryAttributeService $attributes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $defaults  give a top-level category the general default fields
     */
    public function create(array $data, bool $defaults = true): TenantAssetCategory
    {
        if (! empty($data['parent_id'])) {
            $parent = $this->activeCategory($data['parent_id']);
            if (! empty($data['generic_category_id']) && $data['generic_category_id'] !== $parent->generic_category_id) {
                throw ApiException::unprocessable('INVALID_PARENT', 'Subkategori harus berada pada kategori generik yang sama dengan induknya.');
            }
            $data['generic_category_id'] = $parent->generic_category_id;
        } elseif (empty($data['generic_category_id'])) {
            $data['generic_category_id'] = GenericAssetCategory::query()->where('code', self::DEFAULT_GENERIC_CATEGORY)->value('id');
        }

        if (! GenericAssetCategory::query()->whereKey($data['generic_category_id'])->where('status', MasterStatus::ACTIVE)->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori generik tidak ditemukan atau tidak aktif.');
        }

        $category = $this->writer->create(new TenantAssetCategory, $data, 'tenant_category.created', self::AUDIT);

        // Create default attributes for the new category (top-level only; subcategories inherit them)
        if ($defaults) {
            $this->attributes->createDefaultAttributes($category);
        }

        return $category;
    }

    /**
     * Creates the standard categories of an organization with their default fields.
     * Works in system context (provisioning), so the organization is explicit.
     *
     * @return array<string, TenantAssetCategory> by code
     */
    public function installStandard(string $organizationId): array
    {
        $installed = [];
        foreach (StandardCategories::forEdition() as $code => $spec) {
            $installed[$code] = $this->installOne($organizationId, $code, $spec);
        }

        return $installed;
    }

    /** @param array<string, mixed> $spec an entry of StandardCategories */
    public function installOne(string $organizationId, string $code, array $spec): TenantAssetCategory
    {
        $generic = GenericAssetCategory::query()->where('code', $spec['generic'])->value('id');

        return DB::transaction(function () use ($organizationId, $code, $spec, $generic) {
            $category = $this->create([
                'organization_id' => $organizationId,
                'generic_category_id' => $generic,
                'code' => $code,
                'name' => $spec['name'],
                'description' => $spec['description'],
            ], $spec['defaults'] ?? true);
            $this->attributes->createSpecificAttributes($category, $spec['attributes']);

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(TenantAssetCategory $category, array $data): TenantAssetCategory
    {
        $parent = null;
        $moving = array_key_exists('parent_id', $data) && $data['parent_id'] !== $category->parent_id;
        if ($moving && $data['parent_id']) {
            $parent = $this->activeCategory($data['parent_id']);
            if ($parent->generic_category_id !== $category->generic_category_id) {
                throw ApiException::unprocessable('INVALID_PARENT', 'Induk harus berada pada kategori generik yang sama.');
            }
        }
        if (($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertUnused($category);
        }

        return $this->writer->update($category, $data, 'tenant_category.updated', self::AUDIT, function (TenantAssetCategory $c) use ($moving, $parent) {
            if ($moving) {
                MaterializedPath::move($c, $parent);
            }
        });
    }

    public function archive(TenantAssetCategory $category, ?string $reason): TenantAssetCategory
    {
        $this->assertUnused($category);
        if ($this->usage->inUse(MasterUsage::CATEGORY, $category->id)) {
            throw ApiException::conflict('CATEGORY_IN_USE', 'Kategori masih dipakai oleh aset aktif. Pindahkan asetnya ke kategori lain terlebih dahulu.');
        }

        return $this->writer->archive($category, 'tenant_category.archived', $reason);
    }

    public function restore(TenantAssetCategory $category): TenantAssetCategory
    {
        if ($category->parent_id) {
            $this->activeCategory($category->parent_id);
        }

        return $this->writer->restore($category, 'tenant_category.restored');
    }

    public function destroy(TenantAssetCategory $category): void
    {
        $this->assertUnused($category);
        if ($this->usage->inUse(MasterUsage::CATEGORY, $category->id)) {
            throw ApiException::conflict('CATEGORY_IN_USE', 'Kategori masih dipakai oleh aset aktif. Pindahkan asetnya ke kategori lain terlebih dahulu.');
        }

        $category->delete();
    }

    private function activeCategory(string $id): TenantAssetCategory
    {
        $category = TenantAssetCategory::query()->find($id);
        if ($category === null || $category->status !== MasterStatus::ACTIVE) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori induk tidak ditemukan atau tidak aktif.');
        }

        return $category;
    }

    private function assertUnused(TenantAssetCategory $category): void
    {
        $inUse = TenantAssetCategory::query()->where('parent_id', $category->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists()
            || TenantAssetDefinition::query()->where('tenant_category_id', $category->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists();
        if ($inUse) {
            throw ApiException::conflict('CATEGORY_IN_USE', 'Kategori masih memiliki subkategori atau definisi aset yang belum diarsipkan.');
        }
    }
}
