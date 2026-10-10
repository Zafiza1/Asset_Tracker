<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Safe baseline for every environment: reference data only.
 * Run with the owner connection: php artisan db:seed --database=pgsql_owner
 * Demo data is separate: php artisan db:seed --database=pgsql_owner --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        $tenancy->runAsSystem(function () {
            $this->call([
                PermissionSeeder::class,
                GenericReferenceSeeder::class,
                PlatformAdminSeeder::class,
            ]);
        });
    }
}
