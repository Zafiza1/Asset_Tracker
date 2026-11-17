<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `report.preview`: the Custom Report Designer preview endpoint resolves real tenant data,
 * so it is its own permission like generate/download. Granted to the Super Admin and
 * Supervisor templates of existing organizations; the Admin template role and custom
 * roles are left untouched — same policy as the other report_designer codes.
 */
return new class extends Migration
{
    private const CODES = ['report.preview'];

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
