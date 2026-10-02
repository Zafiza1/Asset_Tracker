<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateModule;
use App\Models\TemplateVersion;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Get core modules
        $assetModule = Module::where('slug', 'asset')->first();
        $locationModule = Module::where('slug', 'location')->first();
        $movementModule = Module::where('slug', 'movement')->first();

        if (!$assetModule || !$locationModule || !$movementModule) {
            $this->command->warn('Core modules not found. Please run ModuleSeeder first.');
            return;
        }

        // General Asset Tracker Template
        $generalTemplate = Template::create([
            'name' => 'General Asset Tracker',
            'slug' => 'general-asset-tracker',
            'description' => 'A general-purpose asset tracking template suitable for various industries.',
            'category' => 'general',
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => false,
                'enable_inspection' => false,
            ],
            'metadata' => [
                'icon' => 'box',
                'color' => 'blue',
            ],
        ]);

        $generalVersion = TemplateVersion::create([
            'template_id' => $generalTemplate->id,
            'version' => '1.0.0',
            'description' => 'Initial release of General Asset Tracker',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => false,
                'enable_inspection' => false,
            ],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ]);

        $generalTemplate->update(['current_version_id' => $generalVersion->id]);

        // Associate core modules
        TemplateModule::create([
            'template_version_id' => $generalVersion->id,
            'module_id' => $assetModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 1,
        ]);

        TemplateModule::create([
            'template_version_id' => $generalVersion->id,
            'module_id' => $locationModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 2,
        ]);

        TemplateModule::create([
            'template_version_id' => $generalVersion->id,
            'module_id' => $movementModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 3,
        ]);

        // Industrial Asset Tracker Template
        $industrialTemplate = Template::create([
            'name' => 'Industrial Asset Tracker',
            'slug' => 'industrial-asset-tracker',
            'description' => 'Template for tracking industrial equipment and machinery with maintenance support.',
            'category' => 'industrial',
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => true,
            ],
            'metadata' => [
                'icon' => 'factory',
                'color' => 'orange',
            ],
        ]);

        $industrialVersion = TemplateVersion::create([
            'template_id' => $industrialTemplate->id,
            'version' => '1.0.0',
            'description' => 'Initial release of Industrial Asset Tracker',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => true,
            ],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ]);

        $industrialTemplate->update(['current_version_id' => $industrialVersion->id]);

        TemplateModule::create([
            'template_version_id' => $industrialVersion->id,
            'module_id' => $assetModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 1,
        ]);

        TemplateModule::create([
            'template_version_id' => $industrialVersion->id,
            'module_id' => $locationModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 2,
        ]);

        TemplateModule::create([
            'template_version_id' => $industrialVersion->id,
            'module_id' => $movementModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 3,
        ]);

        // Warehouse Asset Tracker Template
        $warehouseTemplate = Template::create([
            'name' => 'Warehouse Asset Tracker',
            'slug' => 'warehouse-asset-tracker',
            'description' => 'Template optimized for warehouse inventory and logistics operations.',
            'category' => 'warehouse',
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => false,
                'enable_inspection' => true,
            ],
            'metadata' => [
                'icon' => 'warehouse',
                'color' => 'green',
            ],
        ]);

        $warehouseVersion = TemplateVersion::create([
            'template_id' => $warehouseTemplate->id,
            'version' => '1.0.0',
            'description' => 'Initial release of Warehouse Asset Tracker',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => false,
                'enable_inspection' => true,
            ],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ]);

        $warehouseTemplate->update(['current_version_id' => $warehouseVersion->id]);

        TemplateModule::create([
            'template_version_id' => $warehouseVersion->id,
            'module_id' => $assetModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 1,
        ]);

        TemplateModule::create([
            'template_version_id' => $warehouseVersion->id,
            'module_id' => $locationModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 2,
        ]);

        TemplateModule::create([
            'template_version_id' => $warehouseVersion->id,
            'module_id' => $movementModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 3,
        ]);

        // Vehicle Tracker Template
        $vehicleTemplate = Template::create([
            'name' => 'Vehicle Tracker',
            'slug' => 'vehicle-tracker',
            'description' => 'Template for tracking fleet vehicles and mobile assets with GPS integration.',
            'category' => 'vehicle',
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => false,
                'enable_gps' => true,
            ],
            'metadata' => [
                'icon' => 'truck',
                'color' => 'purple',
            ],
        ]);

        $vehicleVersion = TemplateVersion::create([
            'template_id' => $vehicleTemplate->id,
            'version' => '1.0.0',
            'description' => 'Initial release of Vehicle Tracker',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => false,
                'enable_gps' => true,
            ],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ]);

        $vehicleTemplate->update(['current_version_id' => $vehicleVersion->id]);

        TemplateModule::create([
            'template_version_id' => $vehicleVersion->id,
            'module_id' => $assetModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 1,
        ]);

        TemplateModule::create([
            'template_version_id' => $vehicleVersion->id,
            'module_id' => $locationModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 2,
        ]);

        TemplateModule::create([
            'template_version_id' => $vehicleVersion->id,
            'module_id' => $movementModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 3,
        ]);

        // Equipment Tracker Template
        $equipmentTemplate = Template::create([
            'name' => 'Equipment Tracker',
            'slug' => 'equipment-tracker',
            'description' => 'Template for tracking heavy equipment and machinery with maintenance schedules.',
            'category' => 'equipment',
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => true,
            ],
            'metadata' => [
                'icon' => 'cog',
                'color' => 'red',
            ],
        ]);

        $equipmentVersion = TemplateVersion::create([
            'template_id' => $equipmentTemplate->id,
            'version' => '1.0.0',
            'description' => 'Initial release of Equipment Tracker',
            'default_modules' => ['asset', 'location', 'movement'],
            'default_settings' => [
                'enable_maintenance' => true,
                'enable_inspection' => true,
            ],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ]);

        $equipmentTemplate->update(['current_version_id' => $equipmentVersion->id]);

        TemplateModule::create([
            'template_version_id' => $equipmentVersion->id,
            'module_id' => $assetModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 1,
        ]);

        TemplateModule::create([
            'template_version_id' => $equipmentVersion->id,
            'module_id' => $locationModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 2,
        ]);

        TemplateModule::create([
            'template_version_id' => $equipmentVersion->id,
            'module_id' => $movementModule->id,
            'required' => true,
            'default_config' => [],
            'sort_order' => 3,
        ]);

        // Business modules each blueprint brings, after the core ones and in
        // dependency order (delivery needs customer). Pinned to major v1.
        $businessModules = [
            'industrial-asset-tracker' => ['customer', 'delivery', 'maintenance'],
            'vehicle-tracker' => ['maintenance'],
            'warehouse-asset-tracker' => ['inventory', 'inspection'],
            'equipment-tracker' => ['maintenance', 'inspection'],
        ];

        foreach ($businessModules as $templateSlug => $moduleSlugs) {
            $template = Template::where('slug', $templateSlug)->first();
            $version = $template?->currentVersion;
            if (!$version) {
                continue;
            }

            $modules = Module::whereIn('slug', $moduleSlugs)->where('is_core', false)->get()->keyBy('slug');
            foreach ($moduleSlugs as $index => $slug) {
                if (!isset($modules[$slug])) {
                    continue;
                }
                TemplateModule::firstOrCreate(
                    ['template_version_id' => $version->id, 'module_id' => $modules[$slug]->id],
                    ['version_constraint' => '^1.0', 'required' => true, 'default_config' => [], 'sort_order' => 10 + $index]
                );
            }

            $defaults = array_values(array_unique(array_merge($version->default_modules ?? [], $moduleSlugs)));
            $version->update(['default_modules' => $defaults]);
            $template->update(['default_modules' => $defaults]);
        }

        $this->command->info('Templates seeded successfully.');
    }
}
