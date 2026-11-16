<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Custom Report Designer permissions (group "report_designer"): template management
 * (create/update/delete/publish/manage) and usage (generate/download/history.view).
 *
 * They live in their own group so the Admin template — built from codesInGroups([...,'report',...])
 * — never holds them. New organizations get them through RoleTemplates (Super Admin: all,
 * Supervisor: everything but license/invite/profile). This migration grants them to the
 * Super Admin and Supervisor roles of existing organizations; the Admin template role and
 * custom roles are left untouched — a tenant grants the designer permissions deliberately.
 */
return new class extends Migration
{
    private const CODES = [
        'report.create', 'report.update', 'report.delete', 'report.publish', 'report.manage',
        'report.generate', 'report.download', 'report.history.view',
    ];

    private const ROLE_TEMPLATES = [RoleTemplates::SUPER_ADMIN, RoleTemplates::SUPERVISOR];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        PermissionCatalog::sync();
        $permissions = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        $roles = DB::table('roles')->whereIn('template_code', self::ROLE_TEMPLATES)->get(['id', 'organization_id']);

        foreach ($roles as $role) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
