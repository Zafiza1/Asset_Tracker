<?php

namespace App\Domain\Organization\Services;

use App\Domain\Authorization\Services\DataScopeGuard;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterUsage;
use App\Domain\Shared\Masters\MasterWriter;
use App\Domain\Shared\Masters\MaterializedPath;

/**
 * Departments and locations of the current organization. Every reference is
 * resolved through tenant-scoped queries (another tenant's id is "not found"), new
 * references must point to active records, and records still in use cannot be
 * deactivated or archived. Codes are immutable once created.
 */
final class OrganizationStructureService
{
    private const DEPARTMENT_AUDIT = ['code', 'name', 'status'];

    private const LOCATION_AUDIT = ['parent_id', 'location_type_id', 'code', 'name', 'address', 'status'];

    private const LOCATION_TYPE_AUDIT = ['code', 'name', 'description', 'status'];

    public function __construct(
        private readonly MasterWriter $writer,
        private readonly MasterUsage $usage,
        private readonly DataScopeGuard $scopeGuard,
    ) {}

    // ------------------------------------------------------------------ departments

    /** @param array<string, mixed> $data */
    public function createDepartment(array $data): Department
    {
        return $this->writer->create(new Department, $data, 'department.created', self::DEPARTMENT_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateDepartment(Department $department, array $data): Department
    {
        return $this->writer->update($department, $data, 'department.updated', self::DEPARTMENT_AUDIT);
    }

    public function archiveDepartment(Department $department, ?string $reason): Department
    {
        return $this->writer->archive($department, 'department.archived', $reason);
    }

    public function restoreDepartment(Department $department): Department
    {
        return $this->writer->restore($department, 'department.restored');
    }

    // ------------------------------------------------------------------ locations
    //
    // Location scopes cover sub-locations (AssetScope), so the tree itself is access control:
    // an actor limited to some locations may only change nodes inside their scope and may only
    // attach nodes to parents inside it. Otherwise moving a location would pull its assets into
    // the actor's scope, or push in-scope assets to users scoped elsewhere. New top-level
    // locations are for organization-wide actors only.

    /** @param array<string, mixed> $data */
    public function createLocation(array $data): Location
    {
        $this->activeLocationType($data['location_type_id']);
        if (! empty($data['parent_id'])) {
            $this->active(Location::class, $data['parent_id'], 'Lokasi induk');
        }
        $this->assertParentInScope($data['parent_id'] ?? null);

        return $this->writer->create(new Location, $data, 'location.created', self::LOCATION_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateLocation(Location $location, array $data): Location
    {
        $this->assertInScope($location);
        if (isset($data['location_type_id']) && $data['location_type_id'] !== $location->location_type_id) {
            $this->activeLocationType($data['location_type_id']);
        }

        $parent = null;
        $moving = array_key_exists('parent_id', $data) && $data['parent_id'] !== $location->parent_id;
        if ($moving && $data['parent_id']) {
            $parent = $this->active(Location::class, $data['parent_id'], 'Lokasi induk');
        }
        if ($moving) {
            $this->assertParentInScope($parent?->id);
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
        $this->assertInScope($location);
        if ($this->activeChildren($location)) {
            throw ApiException::conflict('LOCATION_HAS_CHILDREN', 'Lokasi masih memiliki sub-lokasi aktif.');
        }
        if ($this->usage->inUse(MasterUsage::LOCATION, $location->id)) {
            throw ApiException::conflict('LOCATION_IN_USE', 'Lokasi masih dipakai oleh aset aktif. Pindahkan asetnya terlebih dahulu.');
        }

        return $this->writer->archive($location, 'location.archived', $reason);
    }

    public function restoreLocation(Location $location): Location
    {
        $this->assertInScope($location);
        if ($location->parent_id) {
            $this->active(Location::class, $location->parent_id, 'Lokasi induk');
        }

        return $this->writer->restore($location, 'location.restored');
    }

    // ------------------------------------------------------------------ location types (organization-owned)

    /** @param array<string, mixed> $data */
    public function createLocationType(array $data): LocationType
    {
        return $this->writer->create(new LocationType, $data, 'location_type.created', self::LOCATION_TYPE_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateLocationType(LocationType $type, array $data): LocationType
    {
        $this->ownLocationType($type);
        if (($data['status'] ?? null) === MasterStatus::INACTIVE && $this->locationTypeInUse($type)) {
            throw ApiException::conflict('LOCATION_TYPE_IN_USE', 'Jenis lokasi masih dipakai oleh lokasi aktif.');
        }

        return $this->writer->update($type, $data, 'location_type.updated', self::LOCATION_TYPE_AUDIT);
    }

    public function archiveLocationType(LocationType $type, ?string $reason): LocationType
    {
        $this->ownLocationType($type);
        if ($this->locationTypeInUse($type)) {
            throw ApiException::conflict('LOCATION_TYPE_IN_USE', 'Jenis lokasi masih dipakai oleh lokasi aktif.');
        }

        return $this->writer->archive($type, 'location_type.archived', $reason);
    }

    public function restoreLocationType(LocationType $type): LocationType
    {
        $this->ownLocationType($type);

        return $this->writer->restore($type, 'location_type.restored');
    }

    // ------------------------------------------------------------------ helpers

    private function assertInScope(Location $location): void
    {
        if (! $this->scopeGuard->allowsLocation($location->id)) {
            throw ApiException::forbidden('LOCATION_OUT_OF_SCOPE', 'Lokasi berada di luar cakupan data Anda.');
        }
    }

    /** NULL = top level. */
    private function assertParentInScope(?string $parentId): void
    {
        if (! $this->scopeGuard->allowsLocation($parentId)) {
            $message = 'Lokasi induk harus berada dalam cakupan data Anda.';
            throw ApiException::unprocessable('LOCATION_OUT_OF_SCOPE', $message, ['fields' => ['parent_id' => [$message]]]);
        }
    }

    /** Built-in types are visible to every organization but only the platform may change them. */
    private function ownLocationType(LocationType $type): void
    {
        if ($type->isBuiltIn()) {
            throw ApiException::forbidden('BUILT_IN_LOCATION_TYPE', 'Jenis lokasi bawaan tidak dapat diubah.');
        }
    }

    private function locationTypeInUse(LocationType $type): bool
    {
        return Location::query()->where('location_type_id', $type->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists();
    }

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

    private function activeChildren(Location $location): bool
    {
        return Location::query()->where('parent_id', $location->id)->where('status', '!=', MasterStatus::ARCHIVED)->exists();
    }
}
