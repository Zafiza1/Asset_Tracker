<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles become Super Admin (platform), Admin (locked, per organization) and Supervisor (read-only).
 * PLATFORM_ADMIN and ORG_ADMIN are renamed; the VIEWER template becomes SUPERVISOR. Holders of the
 * removed templates (ASSET_MANAGER, OPERATOR, APPROVER, AUDITOR) move to SUPERVISOR, pending
 * invitations offering them now offer SUPERVISOR, and those roles are deleted. Custom roles stay.
 */
return new class extends Migration
{
    private const REMOVED = ['ASSET_MANAGER', 'OPERATOR', 'APPROVER', 'AUDITOR'];

    private const SUPERVISOR_PERMISSIONS = ['organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'transaction.view', 'report.view'];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('platform_roles')->where('code', 'PLATFORM_ADMIN')->update(['code' => 'SUPER_ADMIN', 'name' => 'Super Admin', 'updated_at' => now()]);

        DB::table('roles')->where('template_code', 'ORG_ADMIN')
            ->update(['code' => 'ADMIN', 'template_code' => 'ADMIN', 'name' => 'Admin', 'updated_at' => now()]);
        DB::table('roles')->where('template_code', 'VIEWER')
            ->update(['code' => 'SUPERVISOR', 'template_code' => 'SUPERVISOR', 'name' => 'Supervisor', 'updated_at' => now()]);

        $permissionIds = DB::table('permissions')->whereIn('code', self::SUPERVISOR_PERMISSIONS)->pluck('id');

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $supervisorId = DB::table('roles')->where('organization_id', $orgId)->where('template_code', 'SUPERVISOR')->value('id')
                ?? $this->createSupervisor($orgId, $permissionIds);

            $removed = DB::table('roles')->where('organization_id', $orgId)->whereIn('template_code', self::REMOVED)->pluck('id')->all();
            if ($removed === []) {
                continue;
            }

            DB::statement('INSERT INTO user_roles (organization_id, user_id, role_id)
                SELECT DISTINCT organization_id, user_id, ?::uuid FROM user_roles WHERE role_id = ANY(?::uuid[])
                ON CONFLICT DO NOTHING', [$supervisorId, '{'.implode(',', $removed).'}']);
            DB::table('user_roles')->whereIn('role_id', $removed)->delete();

            $pending = DB::table('organization_invitations')->where('organization_id', $orgId)->where('status', 'pending')->get(['id', 'role_ids']);
            foreach ($pending as $invitation) {
                $roleIds = json_decode($invitation->role_ids, true);
                if (array_intersect($roleIds, $removed) === []) {
                    continue;
                }
                $roleIds = array_values(array_unique([...array_diff($roleIds, $removed), $supervisorId]));
                DB::table('organization_invitations')->where('id', $invitation->id)->update(['role_ids' => json_encode($roleIds)]);
            }

            DB::table('role_permissions')->whereIn('role_id', $removed)->delete();
            DB::table('roles')->whereIn('id', $removed)->delete();
        }
    }

    /** Removed roles and their assignments are not restored; only the renames are reverted. */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('platform_roles')->where('code', 'SUPER_ADMIN')->update(['code' => 'PLATFORM_ADMIN', 'name' => 'Platform Administrator', 'updated_at' => now()]);
        DB::table('roles')->where('template_code', 'ADMIN')
            ->update(['code' => 'ORG_ADMIN', 'template_code' => 'ORG_ADMIN', 'name' => 'Administrator Organisasi', 'updated_at' => now()]);
        DB::table('roles')->where('template_code', 'SUPERVISOR')
            ->update(['code' => 'VIEWER', 'template_code' => 'VIEWER', 'name' => 'Viewer', 'updated_at' => now()]);
    }

    /** @param Collection<int, string> $permissionIds */
    private function createSupervisor(string $orgId, $permissionIds): string
    {
        $id = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $id,
            'organization_id' => $orgId,
            'code' => 'SUPERVISOR',
            'name' => 'Supervisor',
            'description' => 'Akses baca aset, transaksi, dan laporan.',
            'template_code' => 'SUPERVISOR',
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert($permissionIds->map(fn ($p) => ['organization_id' => $orgId, 'role_id' => $id, 'permission_id' => $p])->all());

        return $id;
    }
};
