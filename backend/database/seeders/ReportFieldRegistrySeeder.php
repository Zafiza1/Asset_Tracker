<?php

namespace Database\Seeders;

use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Idempotent: syncs the report field registry (system reference data). Runs in the system
 * context itself, so it also works on its own after a deploy:
 * php artisan db:seed --database=pgsql_owner --class=ReportFieldRegistrySeeder
 */
class ReportFieldRegistrySeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        $tenancy->runAsSystem(fn () => FieldCatalog::sync());
    }
}
