<?php

namespace App\Policies;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Services\DashboardAccessService;
use App\Domain\Dashboards\SharePermission;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * Object-level access to dashboards (route middleware already checked dashboard.view).
 * Levels come from DashboardAccessService: view opens it, edit changes widgets and layout,
 * manage changes settings, sharing and deletes it. Opening a dashboard never grants the data
 * its widgets show.
 */
class DashboardPolicy
{
    public function __construct(
        private readonly DashboardAccessService $access,
        private readonly Tenancy $tenancy,
    ) {}

    public function view(User $user, Dashboard $dashboard): bool
    {
        return $this->access->allows($dashboard, SharePermission::VIEW);
    }

    /** Widgets and layout (Phase 3/4). */
    public function update(User $user, Dashboard $dashboard): bool
    {
        return $this->access->allows($dashboard, SharePermission::EDIT);
    }

    /** Settings, explicit template updates, sharing. */
    public function manage(User $user, Dashboard $dashboard): bool
    {
        return $this->access->allows($dashboard, SharePermission::MANAGE);
    }

    /** Only custom dashboards are ever deleted (DashboardService answers DASHBOARD_NOT_DELETABLE). */
    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->manage($user, $dashboard);
    }

    /** Personal dashboards need dashboard.create; organization ones dashboard.manage. */
    public function create(User $user, string $visibility): bool
    {
        if (! $this->tenancy->hasTenant()) {
            return false;
        }
        $context = $this->tenancy->tenant();

        return $context->can('dashboard.view') && $context->can(
            $visibility === DashboardType::ORGANIZATION ? 'dashboard.manage' : 'dashboard.create',
        );
    }
}
