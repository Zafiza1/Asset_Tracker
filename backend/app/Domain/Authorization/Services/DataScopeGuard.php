<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Authorization\DataScope;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Anti-escalation for data scopes (docs/05-rbac-matrix.md §3), the counterpart of
 * RoleManagement::assertGrantable for permissions. An actor limited to some locations or
 * departments can only hand out, take away or restructure what lies within their own scope;
 * an organization-wide actor is unrestricted. A location scope covers the location and all
 * its sub-locations (AssetScope).
 */
final class DataScopeGuard
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $assetScope,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * Every requested scope entry lies within the actor's own scope.
     *
     * @param  list<array{scope_type: string, ref_id?: string|null}>  $scopes
     */
    public function assertGrantable(array $scopes): void
    {
        $actor = $this->actorScope();
        if ($actor->organizationWide) {
            return;
        }
        foreach ($scopes as $scope) {
            if (! $this->covers($actor, $scope['scope_type'], $scope['ref_id'] ?? null)) {
                throw self::escalation();
            }
        }
    }

    /** The target's current scope lies within the actor's own (it is not broader than the actor). */
    public function assertCovers(DataScope $target): void
    {
        $actor = $this->actorScope();
        if ($actor->organizationWide) {
            return;
        }
        $covered = ! $target->organizationWide
            && array_diff($target->departmentIds, $actor->departmentIds) === []
            && $this->assetScope->allowsLocations($target->locationIds);
        if (! $covered) {
            throw self::escalation();
        }
    }

    /** The target member's current scope in this organization lies within the actor's own. */
    public function assertCoversUser(User $user): void
    {
        if (! $this->actorScope()->organizationWide) {
            $this->assertCovers($this->permissions->dataScope($user, $this->tenancy->organizationId()));
        }
    }

    /**
     * Every holder's current scope lies within the actor's own, e.g. before changing the
     * permissions of the role (the change would apply wherever they have access). Covering the
     * union of the holders' entries is the same as covering each holder, so this reads all of
     * them in one query however many holders the role has.
     */
    public function assertCoversRoleHolders(Role $role): void
    {
        if ($this->actorScope()->organizationWide) {
            return;
        }
        $rows = DB::table('user_data_scopes')
            ->where('organization_id', $this->tenancy->organizationId())
            ->whereIn('user_id', DB::table('user_roles')->select('user_id')->where('role_id', $role->id))
            ->get(['scope_type', 'department_id', 'location_id']);

        $this->assertCovers(new DataScope(
            organizationWide: $rows->contains('scope_type', 'organization'),
            departmentIds: $rows->whereNotNull('department_id')->pluck('department_id')->unique()->values()->all(),
            locationIds: $rows->whereNotNull('location_id')->pluck('location_id')->unique()->values()->all(),
        ));
    }

    /**
     * May the actor change the location tree at this node? NULL = the top level, which only an
     * organization-wide actor may change (a new root is outside every location scope).
     */
    public function allowsLocation(?string $locationId): bool
    {
        return $this->assetScope->allowsLocation($locationId);
    }

    private function covers(DataScope $actor, string $type, ?string $ref): bool
    {
        return match ($type) {
            'department' => $ref !== null && in_array($ref, $actor->departmentIds, true),
            'location' => $ref !== null && $this->assetScope->allowsLocation($ref),
            default => false,
        };
    }

    private function actorScope(): DataScope
    {
        return $this->tenancy->tenant()->scope();
    }

    private static function escalation(): ApiException
    {
        return new ApiException('SCOPE_ESCALATION', 'Anda tidak dapat memberikan cakupan data di luar cakupan Anda sendiri.', 403);
    }
}
