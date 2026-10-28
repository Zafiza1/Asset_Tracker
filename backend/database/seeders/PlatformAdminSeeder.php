<?php

namespace Database\Seeders;

use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates the first platform administrator from PLATFORM_ADMIN_EMAIL / PLATFORM_ADMIN_PASSWORD.
 * Nothing is created when they are not set: there is no default account with a known password.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL');
        $password = env('PLATFORM_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('PLATFORM_ADMIN_EMAIL/PLATFORM_ADMIN_PASSWORD not set: no platform administrator created.');

            return;
        }
        // users and platform_user_roles are writable only in the system context; set it here too,
        // so the seeder also works on its own (--class=PlatformAdminSeeder).
        $tenancy->runAsSystem(function () use ($email, $password) {
            if (User::query()->where('email', $email)->exists()) {
                $this->command?->info("Platform administrator {$email} already exists.");

                return;
            }

            $role = PlatformRole::query()->where('code', RoleTemplates::SUPER_ADMIN)->firstOrFail();

            DB::transaction(function () use ($email, $password, $role) {
                $user = new User(['name' => 'Super Admin', 'email' => $email, 'password' => $password]);
                $user->forceFill([
                    'user_type' => User::TYPE_PLATFORM,
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => true,
                ])->save();

                DB::table('platform_user_roles')->insert(['user_id' => $user->id, 'platform_role_id' => $role->id]);
            });

            $this->command?->info("Platform administrator {$email} created (must change password at first login).");
        });
    }
}
