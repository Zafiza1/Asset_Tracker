<?php

namespace Database\Seeders;

use App\Domain\Dashboards\Catalog\SystemTemplates;
use App\Domain\Dashboards\Catalog\SystemWidgets;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Idempotent: syncs the widget catalog and the system dashboard templates. Runs in the system
 * context itself, so it also works on its own after a deploy:
 * php artisan db:seed --database=pgsql_owner --class=DashboardCatalogSeeder
 */
class DashboardCatalogSeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        $tenancy->runAsSystem(function () {
            SystemWidgets::sync();
            SystemTemplates::sync();
        });
    }
}
