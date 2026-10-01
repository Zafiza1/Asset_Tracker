<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Services\ModuleService;
use Illuminate\Database\Seeder;

/**
 * Demo data: installs and enables modules on the seeded demo projects. This
 * is project configuration (Section 48) — the same thing a customer does
 * through the API — not platform code.
 */
class ProjectModuleSeeder extends Seeder
{
    public function run(ModuleService $modules): void
    {
        $plan = [
            'cylinder-asset-tracker' => ['customer', 'delivery', 'maintenance'],
            'vehicle-tracker' => ['maintenance'],
            'factory-equipment-tracker' => ['maintenance', 'inspection'],
            'warehouse-tracker' => ['inventory', 'inspection'],
        ];

        foreach ($plan as $projectSlug => $moduleSlugs) {
            $project = Project::where('slug', $projectSlug)->first();

            if (!$project) {
                continue;
            }

            // Listed in dependency order, so each one can be enabled at once.
            foreach ($moduleSlugs as $moduleSlug) {
                $modules->enable($modules->install($project, $moduleSlug));
            }
        }

        $this->command->info('Project modules seeded successfully.');
    }
}
