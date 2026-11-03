<?php

use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Default roles may only hold their template's permissions (RoleTemplates::ceiling). Removes
 * what existing default roles hold beyond it, e.g. audit.view on ADMIN after the template
 * dropped it. Not reversible: the removed grants are not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        foreach (array_keys(RoleTemplates::tenant()) as $templateCode) {
            DB::table('role_permissions')
                ->whereIn('role_id', DB::table('roles')->select('id')->where('template_code', $templateCode))
                ->whereNotIn('permission_id', DB::table('permissions')->select('id')->whereIn('code', RoleTemplates::ceiling($templateCode)))
                ->delete();
        }
    }

    public function down(): void
    {
        // The removed grants are outside the templates and are not restored.
    }
};
