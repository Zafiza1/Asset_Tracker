<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migrate to new license system without platform admin.
     * 1. Convert first admin in each organization to SUPER_ADMIN
     * 2. Convert seat pack licenses to individual user licenses
     * 3. Platform user cleanup (optional - keep for reference)
     */
    public function up(): void
    {
        DB::transaction(function () {
            // Step 1: Add SUPER_ADMIN role to each organization if not exists
            $organizations = DB::table('organizations')->pluck('id');

            foreach ($organizations as $orgId) {
                // Check if SUPER_ADMIN role exists
                $superAdminRole = DB::table('roles')
                    ->where('organization_id', $orgId)
                    ->where('code', 'SUPER_ADMIN')
                    ->first();

                if (!$superAdminRole) {
                    // Get tenant permissions
                    $tenantPermissions = DB::table('permissions')
                        ->where('scope', 'tenant')
                        ->pluck('id');

                    // Create SUPER_ADMIN role
                    $superAdminRoleId = DB::table('roles')->insertGetId([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'organization_id' => $orgId,
                        'code' => 'SUPER_ADMIN',
                        'name' => 'Super Admin',
                        'description' => 'Pemilik organisasi dengan akses penuh dan hak beli lisensi.',
                        'is_locked' => true,
                        'template_code' => 'SUPER_ADMIN',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Attach all tenant permissions
                    foreach ($tenantPermissions as $permId) {
                        DB::table('role_permissions')->insert([
                            'role_id' => $superAdminRoleId,
                            'permission_id' => $permId,
                            'organization_id' => $orgId,
                        ]);
                    }

                    // Assign SUPER_ADMIN to first admin in organization
                    $firstAdmin = DB::table('organization_members')
                        ->where('organization_id', $orgId)
                        ->where('status', 'active')
                        ->orderBy('created_at')
                        ->first();

                    if ($firstAdmin) {
                        // Remove ADMIN role if exists
                        DB::table('role_user')
                            ->where('user_id', $firstAdmin->user_id)
                            ->where('organization_id', $orgId)
                            ->delete();

                        // Assign SUPER_ADMIN role
                        DB::table('role_user')->insert([
                            'user_id' => $firstAdmin->user_id,
                            'role_id' => $superAdminRoleId,
                            'organization_id' => $orgId,
                        ]);
                    }
                }
            }

            // Step 2: Convert seat pack licenses to individual user licenses
            $seatPacks = DB::table('organization_licenses')
                ->where('kind', 'seats')
                ->whereNull('revoked_at')
                ->get();

            foreach ($seatPacks as $seatPack) {
                $seats = $seatPack->seats;

                // Delete the seat pack
                DB::table('organization_licenses')->where('id', $seatPack->id)->delete();

                // Create individual licenses for each seat
                for ($i = 0; $i < $seats; $i++) {
                    DB::table('organization_licenses')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid(),
                        'organization_id' => $seatPack->organization_id,
                        'kind' => 'base',
                        'seats' => 1,
                        'source' => 'migration',
                        'reference' => 'MIGRATED-' . $seatPack->reference,
                        'notes' => 'Migrated from seat pack: ' . ($seatPack->notes ?? 'No notes'),
                        'starts_at' => $seatPack->starts_at,
                        'expires_at' => $seatPack->expires_at,
                        'created_at' => $seatPack->created_at,
                        'updated_at' => now(),
                    ]);
                }
            }

            // Note: Platform users and roles are kept for reference but will not be used
            // The platform routes and middleware have been removed
        });
    }

    /**
     * Reverse the migration (for development/testing only).
     */
    public function down(): void
    {
        // This is complex to reverse, so we don't implement rollback
        // In production, this migration should be run carefully
        throw new \Exception('This migration cannot be rolled back automatically.');
    }
};
