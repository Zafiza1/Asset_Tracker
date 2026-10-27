<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;

/** The default "Location" attribute duplicates the asset's built-in location (Lokasi) field. */
class RemoveLocationFromDefaultAttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Removing Location from default attributes...');

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () {
            $deleted = TenantCategoryAttribute::query()
                ->where('label', 'Location')
                ->where('is_default', true)
                ->delete();

            $this->command->line("  Deleted {$deleted} Location default attributes");
        });

        $this->command->info('Location removed successfully.');
    }
}
