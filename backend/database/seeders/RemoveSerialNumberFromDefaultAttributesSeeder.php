<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;

class RemoveSerialNumberFromDefaultAttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Removing Serial Number from default attributes...');

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () {
            $deleted = TenantCategoryAttribute::query()
                ->where('label', 'Serial Number')
                ->where('is_default', true)
                ->delete();

            $this->command->line("  Deleted {$deleted} Serial Number default attributes");
        });

        $this->command->info('Serial Number removed successfully.');
    }
}
