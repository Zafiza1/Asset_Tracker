<?php

namespace App\Domain\Assets;

use App\Domain\Authorization\DataScope;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Applies the user's data scope (docs/05-rbac-matrix.md §3) to assets:
 *  - organization: every asset;
 *  - location X: assets at X and its sub-locations;
 *  - department D: assets of department D (F-09 §3.3);
 *  - several entries: the union.
 * Assets with neither a location nor a department are visible to organization-wide users
 * only (fail-closed). A write may never leave an asset outside the writer's own scope
 * (allowsAsset), so nobody can push an asset out of, or pull one into, their reach.
 */
final class AssetScope
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Filter a query on assets. `$locationColumn` may be qualified ("assets.location_id");
     * the department column is taken from the same table.
     */
    public function apply(Builder $query, string $locationColumn = 'location_id'): Builder
    {
        $scope = $this->scope();
        if ($scope->organizationWide) {
            return $query;
        }
        if ($scope->locationIds === [] && $scope->departmentIds === []) {
            return $query->whereRaw('false');
        }
        $departmentColumn = substr($locationColumn, 0, -strlen('location_id')).'department_id';

        return $query->where(function ($w) use ($scope, $locationColumn, $departmentColumn) {
            if ($scope->locationIds !== []) {
                $w->orWhereIn($locationColumn, $this->locationsInScope($scope));
            }
            if ($scope->departmentIds !== []) {
                $w->orWhereIn($departmentColumn, $scope->departmentIds);
            }
        });
    }

    /**
     * Would an asset at this location and of this department be within the user's scope?
     * Every write that sets either checks the result, so the asset stays within reach.
     */
    public function allowsAsset(?string $locationId, ?string $departmentId): bool
    {
        $scope = $this->scope();
        if ($scope->organizationWide) {
            return true;
        }
        if ($departmentId !== null && in_array($departmentId, $scope->departmentIds, true)) {
            return true;
        }

        return $this->allowsLocation($locationId);
    }

    /** Has the user department entries (they may then place their department's assets anywhere)? */
    public function hasDepartments(): bool
    {
        return $this->scope()->departmentIds !== [];
    }

    public function isOrganizationWide(): bool
    {
        return $this->scope()->organizationWide;
    }

    /** Is this location within the user's location entries? NULL = no location. */
    public function allowsLocation(?string $locationId): bool
    {
        $scope = $this->scope();
        if ($scope->organizationWide) {
            return true;
        }
        // No location entries: an empty OR group would match every location (fail-open).
        if ($locationId === null || $scope->locationIds === []) {
            return false;
        }

        return $this->locationsInScope($scope)->whereKey($locationId)->exists();
    }

    /**
     * Are all these locations within the user's scope? One query for the whole list.
     *
     * @param  list<string>  $locationIds
     */
    public function allowsLocations(array $locationIds): bool
    {
        $scope = $this->scope();
        $ids = array_values(array_unique($locationIds));
        if ($ids === [] || $scope->organizationWide) {
            return true;
        }
        if ($scope->locationIds === []) {
            return false;
        }

        return $this->locationsInScope($scope)->whereKey($ids)->count() === count($ids);
    }

    private function locationsInScope(DataScope $scope): Builder
    {
        return Location::query()->select('id')->where(function ($w) use ($scope) {
            foreach ($scope->locationIds as $id) {
                $w->orWhere('path', 'like', '%/'.$id.'/%');
            }
        });
    }

    private function scope(): DataScope
    {
        return $this->tenancy->tenant()->scope();
    }
}
