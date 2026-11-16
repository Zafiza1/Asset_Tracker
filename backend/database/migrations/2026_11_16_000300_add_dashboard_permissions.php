<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard permissions (group "dashboard"): dashboard.view, dashboard.create, dashboard.manage.
 * Every default role holds the whole group (RoleTemplates). Custom roles that could open the
 * old dashboard (report.view) keep seeing dashboards: they get dashboard.view.
 *
 * Opening a dashboard never grants its data: each widget still checks the data permissions
 * (report.view, asset.view, transaction.view, audit.view) and the member's data scope.
 */
return new class extends Migration
{
    private const CODES = ['dashboard.view', 'dashboard.create', 'dashboard.manage'];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        PermissionCatalog::sync();
        $permissions = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id', 'code');

        $defaultRoles = DB::table('roles')->whereIn('template_code', array_keys(RoleTemplates::tenant()))->get(['id', 'organization_id']);
        foreach ($defaultRoles as $role) {
            foreach ($permissions as $permissionId) {
                $this->grant($role, $permissionId);
            }
        }

        $viewers = DB::table('roles')->whereNull('template_code')
            ->whereIn('id', DB::table('role_permissions')->select('role_id')
                ->where('permission_id', DB::table('permissions')->where('code', 'report.view')->value('id')))
            ->get(['id', 'organization_id']);
        foreach ($viewers as $role) {
            $this->grant($role, $permissions['dashboard.view']);
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $ids = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function grant(object $role, string $permissionId): void
    {
        DB::table('role_permissions')->insertOrIgnore([
            'organization_id' => $role->organization_id,
            'role_id' => $role->id,
            'permission_id' => $permissionId,
        ]);
    }
};
