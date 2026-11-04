<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Earlier versions deactivated people on the account (users.status) and could leave a membership
 * "suspended". Today the status lives on the membership and only active/deactivated are written,
 * so such people show as inactive but cannot be managed or invited again.
 *
 * Suspended memberships become deactivated and release their license code, as deactivating does.
 * An inactive tenant account without any active membership becomes active again: it still has no
 * organization to work in until it accepts a new invitation. Accounts that still have an active
 * membership are left alone, because activating them would give access back.
 *
 * Not reversible: the earlier statuses are not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $now = now();

        foreach (DB::table('organization_members')->where('status', 'suspended')->get(['organization_id', 'user_id']) as $member) {
            DB::table('license_codes')
                ->where('organization_id', $member->organization_id)
                ->where('assigned_to_user_id', $member->user_id)
                ->where('status', 'assigned')
                ->update(['status' => 'available', 'assigned_to_user_id' => null, 'assigned_at' => null, 'updated_at' => $now]);
        }
        DB::table('organization_members')->where('status', 'suspended')->update(['status' => 'deactivated', 'updated_at' => $now]);

        DB::table('users')
            ->where('user_type', 'tenant')
            ->whereIn('status', ['suspended', 'deactivated'])
            ->whereExists(fn ($q) => $q->from('organization_members')->whereColumn('organization_members.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->from('organization_members')->whereColumn('organization_members.user_id', 'users.id')->where('organization_members.status', 'active'))
            ->update(['status' => 'active', 'updated_at' => $now]);
    }

    public function down(): void
    {
        // The earlier statuses are not recorded and are not restored.
    }
};
