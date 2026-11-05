<?php

use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Only Super Admin invites users (RoleTemplates::mayInvite). Removes user.create from every other
 * existing role, default or custom. Not reversible: the removed grants are not recorded.
 * Invitations already sent stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')->select('id')->where('code', RoleTemplates::INVITE_PERMISSION))
            ->whereNotIn('role_id', DB::table('roles')->select('id')->where('template_code', RoleTemplates::SUPER_ADMIN))
            ->delete();
    }

    public function down(): void
    {
        // The removed grants are not restored.
    }
};
