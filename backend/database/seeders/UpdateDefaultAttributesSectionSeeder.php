<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateDefaultAttributesSectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Updating section for existing default attributes...');

        // Mapping label to section
        $labelToSection = [
            'Name' => 'basic_information',
            'Brand' => 'basic_information',
            'Model' => 'basic_information',
            'UOM' => 'basic_information',
            'Owner' => 'location_ownership',
            'Responsible Person' => 'location_ownership',
            'Status' => 'status_condition',
            'Operating Hours' => 'status_condition',
            'Condition' => 'status_condition',
        ];

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () use ($labelToSection) {
            // First, show current state
            $this->command->line('  Current default attributes:');
            $current = TenantCategoryAttribute::query()
                ->where('is_default', true)
                ->select('label', 'section')
                ->get();
            foreach ($current as $attr) {
                $this->command->line("    - {$attr->label}: section = " . ($attr->section ?? 'NULL'));
            }

            $updated = 0;

            foreach ($labelToSection as $label => $section) {
                $count = TenantCategoryAttribute::query()
                    ->where('label', $label)
                    ->where('is_default', true)
                    ->update(['section' => $section]);

                $updated += $count;
                if ($count > 0) {
                    $this->command->line("  Updated {$count} '{$label}' to section '{$section}'");
                }
            }

            $this->command->line("  Total updated: {$updated} default attributes");
        });

        $this->command->info('Section update completed successfully.');
    }
}
