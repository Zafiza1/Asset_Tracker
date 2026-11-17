<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardShare;
use App\Domain\Dashboards\SharePermission;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Who may do what with a dashboard, as a SharePermission level (view < edit < manage) or null.
 * The single place DashboardPolicy, listings and the default-dashboard check ask.
 *
 *  - Everything needs dashboard.view; nothing here grants the data widgets show (widgets
 *    check the data permissions and the member's data scope themselves).
 *  - Personal dashboard: its owner manages it; nobody else, admins included (RLS agrees).
 *  - Overview and category dashboards: every member views them; dashboard.manage manages them.
 *  - Organization custom dashboard: its creator and dashboard.manage holders manage it; other
 *    members get the highest level shared with them directly or through one of their roles.
 */
final class DashboardAccessService
{
    /**
     * Role ids per tenant context: a new context per request, so a singleton never serves stale roles.
     *
     * @var WeakMap<TenantContext, list<string>>
     */
    private WeakMap $roleIds;

    public function __construct(private readonly Tenancy $tenancy)
    {
        $this->roleIds = new WeakMap;
    }

    public function level(Dashboard $dashboard): ?string
    {
        $context = $this->context();
        if ($context === null || $dashboard->organization_id !== $context->organizationId() || ! $context->can('dashboard.view')) {
            return null;
        }
        $userId = $context->userId();

        if ($dashboard->isPersonal()) {
            return $dashboard->created_by === $userId ? SharePermission::MANAGE : null;
        }
        if ($context->can('dashboard.manage') || $dashboard->created_by === $userId) {
            return SharePermission::MANAGE;
        }
        if ($dashboard->isSystemManaged()) {
            return SharePermission::VIEW;
        }

        return SharePermission::highest($this->sharesOf($dashboard, $context)->pluck('permission'));
    }

    public function allows(Dashboard $dashboard, string $required): bool
    {
        return SharePermission::includes($this->level($dashboard), $required);
    }

    /** Narrow a dashboards query to the ones the current member may at least view (one query, no N+1). */
    public function scopeAccessible(Builder $query): Builder
    {
        $context = $this->context();
        if ($context === null || ! $context->can('dashboard.view')) {
            return $query->whereRaw('false');
        }
        $userId = $context->userId();
        $manager = $context->can('dashboard.manage');
        $roleIds = $this->roleIds($context);

        return $query->where(fn ($any) => $any
            ->where(fn ($own) => $own->where('dashboards.visibility', DashboardType::PERSONAL)->where('dashboards.created_by', $userId))
            ->orWhere(fn ($org) => $org->where('dashboards.visibility', DashboardType::ORGANIZATION)
                ->when(! $manager, fn ($q) => $q->where(fn ($reach) => $reach
                    ->whereIn('dashboards.dashboard_type', [DashboardType::OVERVIEW, DashboardType::CATEGORY])
                    ->orWhere('dashboards.created_by', $userId)
                    ->orWhereExists(fn ($share) => $share->select(DB::raw(1))->from('dashboard_shares')
                        ->whereColumn('dashboard_shares.dashboard_id', 'dashboards.id')
                        ->where(fn ($target) => $target->where('dashboard_shares.user_id', $userId)
                            ->when($roleIds !== [], fn ($t) => $t->orWhereIn('dashboard_shares.role_id', $roleIds))))))));
    }

    /** Eager-load constraint: only the shares that concern the current member. */
    public function constrainShares(Builder $query): Builder
    {
        $context = $this->context();
        if ($context === null) {
            return $query->whereRaw('false');
        }
        $roleIds = $this->roleIds($context);

        return $query->where(fn ($target) => $target->where('user_id', $context->userId())
            ->when($roleIds !== [], fn ($t) => $t->orWhereIn('role_id', $roleIds)));
    }

    /** @return Collection<int, DashboardShare> */
    private function sharesOf(Dashboard $dashboard, TenantContext $context): Collection
    {
        $userId = $context->userId();
        $roleIds = $this->roleIds($context);
        $shares = $dashboard->relationLoaded('shares')
            ? $dashboard->shares
            : $dashboard->shares()->where(fn ($q) => $this->constrainShares($q))->get();

        // Filter again: the relation may have been loaded without the member constraint.
        return $shares->filter(fn (DashboardShare $share) => $share->user_id === $userId
            || ($share->role_id !== null && in_array($share->role_id, $roleIds, true)));
    }

    /** @return list<string> */
    private function roleIds(TenantContext $context): array
    {
        return $this->roleIds[$context] ??= DB::table('user_roles')
            ->where('organization_id', $context->organizationId())
            ->where('user_id', $context->userId())
            ->pluck('role_id')->all();
    }

    private function context(): ?TenantContext
    {
        return $this->tenancy->hasTenant() ? $this->tenancy->tenant() : null;
    }
}
