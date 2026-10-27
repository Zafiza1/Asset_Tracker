<?php

namespace App\Domain\Assets;

use App\Domain\Authorization\DataScope;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Applies the user's data scope (docs/05-rbac-matrix.md §3) to assets through their location:
 *  - organization: every asset;
 *  - location X: assets at X and its sub-locations.
 * Assets have no department, so department scopes alone see no assets, and assets without
 * a location are visible to organization-wide users only (fail-closed).
 */
final class AssetScope
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /** Filter a query on assets (or on a table with an asset location column). */
    public function apply(Builder $query, string $locationColumn = 'location_id'): Builder
    {
        $scope = $this->scope();
        if ($scope->organizationWide) {
            return $query;
        }
        if ($scope->locationIds === []) {
            return $query->whereRaw('false');
        }

        return $query->whereIn($locationColumn, $this->locationsInScope($scope));
    }

    public function isOrganizationWide(): bool
    {
        return $this->scope()->organizationWide;
    }

    /** May the user place an asset at this location? NULL = no location. */
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
