<?php

namespace App\Domain\Organization\Services;

use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Masters\MaterializedPath;

/**
 * Branches, departments and locations of the current organization. Every reference is
 * resolved through tenant-scoped queries (another tenant's id is "not found"), new
 * references must point to active records, and records still in use cannot be
 * deactivated or archived. Codes are immutable once created.
 */
final class OrganizationStructureService
{
    private const BRANCH_AUDIT = ['code', 'name', 'address', 'phone', 'status'];

    private const DEPARTMENT_AUDIT = ['branch_id', 'code', 'name', 'status'];

    private const LOCATION_AUDIT = ['branch_id', 'parent_id', 'location_type_id', 'code', 'name', 'address', 'status'];

    public function __construct(private readonly MasterWriter $writer) {}

    // ------------------------------------------------------------------ branches

    /** @param array<string, mixed> $data */
    public function createBranch(array $data): Branch
    {
        return $this->writer->create(new Branch, $data, 'branch.created', self::BRANCH_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        if (($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertBranchUnused($branch);
        }

        return $this->writer->update($branch, $data, 'branch.updated', self::BRANCH_AUDIT);
    }

    public function archiveBranch(Branch $branch, ?string $reason): Branch
    {
        $this->assertBranchUnused($branch);

        return $this->writer->archive($branch, 'branch.archived', $reason);
    }

    public function restoreBranch(Branch $branch): Branch
    {
        return $this->writer->restore($branch, 'branch.restored');
    }

    // ------------------------------------------------------------------ departments

    /** @param array<string, mixed> $data */
    public function createDepartment(array $data): Department
    {
        if (! empty($data['branch_id'])) {
            $this->active(Branch::class, $data['branch_id'], 'Cabang');
        }

        return $this->writer->create(new Department, $data, 'department.created', self::DEPARTMENT_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateDepartment(Department $department, array $data): Department
    {
        if (! empty($data['branch_id']) && $data['branch_id'] !== $department->branch_id) {
            $this->active(Branch::class, $data['branch_id'], 'Cabang');
        }

        return $this->writer->update($department, $data, 'department.updated', self::DEPARTMENT_AUDIT);
    }

    public function archiveDepartment(Department $department, ?string $reason): Department
    {
        return $this->writer->archive($department, 'department.archived', $reason);
    }

    public function restoreDepartment(Department $department): Department
    {
        if ($department->branch_id) {
            $this->active(Branch::class, $department->branch_id, 'Cabang induk');
        }

        return $this->writer->restore($department, 'department.restored');
    }

    // ------------------------------------------------------------------ locations

    /** @param array<string, mixed> $data */
    public function createLocation(array $data): Location
    {
        $this->active(Branch::class, $data['branch_id'], 'Cabang');
        $this->activeLocationType($data['location_type_id']);
        if (! empty($data['parent_id'])) {
            $parent = $this->active(Location::class, $data['parent_id'], 'Lokasi induk');
            $this->assertSameBranch($parent, $data['branch_id']);
        }

        return $this->writer->create(new Location, $data, 'location.created', self::LOCATION_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateLocation(Location $location, array $data): Location
    {
        $branchId = $data['branch_id'] ?? $location->branch_id;
        if ($branchId !== $location->branch_id) {
            $this->active(Branch::class, $branchId, 'Cabang');
            if ($this->activeChildren($location)) {
                throw ApiException::conflict('LOCATION_HAS_CHILDREN', 'Cabang lokasi yang memiliki sub-lokasi tidak dapat diubah.');
            }
        }
        if (isset($data['location_type_id']) && $data['location_type_id'] !== $location->location_type_id) {
            $this->activeLocationType($data['location_type_id']);
        }

        $parent = null;
        $moving = array_key_exists('parent_id', $data) && $data['parent_id'] !== $location->parent_id;
        if ($moving && $data['parent_id']) {
            $parent = $this->active(Location::class, $data['parent_id'], 'Lokasi induk');
        }
        $parentForBranch = $moving ? $parent : $location->parent;
        if ($parentForBranch !== null) {
            $this->assertSameBranch($parentForBranch, $branchId);
        }
        if (($data['status'] ?? null) === MasterStatus::INACTIVE && $this->activeChildren($location)) {
            throw ApiException::conflict('LOCATION_HAS_CHILDREN', 'Lokasi masih memiliki sub-lokasi aktif.');
        }

        return $this->writer->update($location, $data, 'location.updated', self::LOCATION_AUDIT, function (Location $l) use ($moving, $parent) {
            if ($moving) {
                MaterializedPath::move($l, $parent);
            }
        });
    }

    public function archiveLocation(Location $location, ?string $reason): Location
    {
        if ($this->activeChildren($location)) {
            throw ApiException::conflict('LOCATION_HAS_CHILDREN', 'Lokasi masih memiliki sub-lokasi aktif.');
        }

        return $this->writer->archive($location, 'location.archived', $reason);
    }

    public function restoreLocation(Location $location): Location
    {
        $this->active(Branch::class, $location->branch_id, 'Cabang lokasi');
        if ($location->parent_id) {
            $this->active(Location::class, $location->parent_id, 'Lokasi induk');
        }

        return $this->writer->restore($location, 'location.restored');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function active(string $model, string $id, string $label)
    {
        $record = $model::query()->find($id);
        if ($record === null || $record->status !== MasterStatus::ACTIVE) {
            throw ApiException::unprocessable('INVALID_REFERENCE', "{$label} tidak ditemukan atau tidak aktif.");
        }

        return $record;
    }

    private function activeLocationType(string $id): void
    {
        if (! LocationType::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Jenis lokasi tidak ditemukan atau tidak aktif.');
        }
    }

    private function assertSameBranch(Location $parent, string $branchId): void
    {
        if ($parent->branch_id !== $branchId) {
            throw ApiException::unprocessable('INVALID_PARENT', 'Lokasi induk harus berada di cabang yang sama.');
        }
    }

    private function activeChildren(Location $location): bool
    {
        return Location::query()->where('parent_id', $location->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists();
    }

    private function assertBranchUnused(Branch $branch): void
    {
        $inUse = Department::query()->where('branch_id', $branch->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists()
            || Location::query()->where('branch_id', $branch->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists();
        if ($inUse) {
            throw ApiException::conflict('BRANCH_IN_USE', 'Cabang masih memiliki departemen atau lokasi yang belum diarsipkan.');
        }
    }
}
