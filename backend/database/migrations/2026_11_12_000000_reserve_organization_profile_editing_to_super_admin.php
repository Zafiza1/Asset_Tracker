<?php

use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Only Super Admin edits the organization profile (RoleTemplates::mayManageOrganization), and
 * every default role reads it. Removes organization.manage from every other existing role,
 * default or custom, and grants organization.view to the Admin roles that lack it.
 * Not reversible: the removed grants are not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')->select('id')->where('code', RoleTemplates::ORGANIZATION_MANAGE_PERMISSION))
            ->whereNotIn('role_id', DB::table('roles')->select('id')->where('template_code', RoleTemplates::SUPER_ADMIN))
            ->delete();

        $viewId = DB::table('permissions')->where('code', RoleTemplates::ORGANIZATION_VIEW_PERMISSION)->where('scope', 'tenant')->value('id');
        if ($viewId === null) {
            return;
        }
        $roles = DB::table('roles')
            ->where('template_code', RoleTemplates::ADMIN)
            ->whereNotExists(fn ($q) => $q->from('role_permissions')->whereColumn('role_permissions.role_id', 'roles.id')->where('permission_id', $viewId))
            ->get(['id', 'organization_id']);
        foreach ($roles as $role) {
            DB::table('role_permissions')->insert(['organization_id' => $role->organization_id, 'role_id' => $role->id, 'permission_id' => $viewId]);
        }
    }

    public function down(): void
    {
        // The removed grants are not restored.
    }
};
