<?php

namespace App\Domain\TenantMaster\Services;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Masters\MaterializedPath;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;

/**
 * Organization categories. A root category picks the generic category it specializes;
 * subcategories inherit that anchor from their parent, so a tree never mixes generic
 * categories. The anchor and code never change (also enforced by a DB trigger).
 */
final class TenantCategoryService
{
    private const AUDIT = ['parent_id', 'generic_category_id', 'code', 'name', 'description', 'status'];

    public function __construct(private readonly MasterWriter $writer) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): TenantAssetCategory
    {
        if (! empty($data['parent_id'])) {
            $parent = $this->activeCategory($data['parent_id']);
            if (! empty($data['generic_category_id']) && $data['generic_category_id'] !== $parent->generic_category_id) {
                throw ApiException::unprocessable('INVALID_PARENT', 'Subkategori harus berada pada kategori generik yang sama dengan induknya.');
            }
            $data['generic_category_id'] = $parent->generic_category_id;
        } elseif (empty($data['generic_category_id'])) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => ['generic_category_id' => ['Kategori generik wajib dipilih untuk kategori utama.']]]);
        }

        if (! GenericAssetCategory::query()->whereKey($data['generic_category_id'])->where('status', MasterStatus::ACTIVE)->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Kategori generik tidak ditemukan atau tidak aktif.');
        }

        return $this->writer->create(new TenantAssetCategory, $data, 'tenant_category.created', self::AUDIT);
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

        return $this->writer->archive($category, 'tenant_category.archived', $reason);
    }

    public function restore(TenantAssetCategory $category): TenantAssetCategory
    {
        if ($category->parent_id) {
            $this->activeCategory($category->parent_id);
        }

        return $this->writer->restore($category, 'tenant_category.restored');
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
