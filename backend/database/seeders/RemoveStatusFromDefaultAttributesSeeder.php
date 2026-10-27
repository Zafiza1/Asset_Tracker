<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;

class RemoveStatusFromDefaultAttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Removing Status from default attributes...');

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () {
            $deleted = TenantCategoryAttribute::query()
                ->where('label', 'Status')
                ->where('is_default', true)
                ->delete();

            $this->command->line("  Deleted {$deleted} Status default attributes");
        });

        $this->command->info('Status removed successfully.');
    }
}
