<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;

/** The default "Name" attribute duplicates the asset's built-in name (Nama aset) field. */
class RemoveNameFromDefaultAttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Removing Name from default attributes...');

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () {
            $deleted = TenantCategoryAttribute::query()
                ->where('label', 'Name')
                ->where('is_default', true)
                ->delete();

            $this->command->line("  Deleted {$deleted} Name default attributes");
        });

        $this->command->info('Name removed successfully.');
    }
}
