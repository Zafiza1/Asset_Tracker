<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        // Get organizations and templates
        $orgSurya = Organization::where('slug', 'pt-surya-inti-gas')->first();
        $orgABC = Organization::where('slug', 'pt-abc-logistics')->first();
        $orgXYZ = Organization::where('slug', 'pt-xyz-manufacturing')->first();

        $templateIndustrial = Template::where('slug', 'industrial-asset-tracker')->first();
        $templateVehicle = Template::where('slug', 'vehicle-tracker')->first();
        $templateWarehouse = Template::where('slug', 'warehouse-asset-tracker')->first();
        $templateEquipment = Template::where('slug', 'equipment-tracker')->first();

        // Get users
        $userSuryaAdmin = User::where('email', 'admin@suryaintigas.com')->first();
        $userSuryaManager = User::where('email', 'manager@suryaintigas.com')->first();
        $userSuryaOperator = User::where('email', 'operator@suryaintigas.com')->first();
        $userABCAdmin = User::where('email', 'admin@abclogistics.com')->first();
        $userABCManager = User::where('email', 'manager@abclogistics.com')->first();
        $userXYZAdmin = User::where('email', 'admin@xyzmanufacturing.com')->first();

        // Create projects for PT Surya Inti Gas
        $projectCylinder = Project::create([
            'organization_id' => $orgSurya->id,
            'template_id' => $templateIndustrial->id,
            'name' => 'Cylinder Asset Tracker',
            'slug' => 'cylinder-asset-tracker',
            'description' => 'Track industrial gas cylinders',
            'status' => 'active',
            'settings' => [
                'enable_rfid' => true,
                'enable_gps' => true,
            ],
        ]);

        // Attach users to Cylinder project
        $projectCylinder->users()->attach($userSuryaAdmin->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);
        $projectCylinder->users()->attach($userSuryaManager->id, [
            'role' => 'manager',
            'joined_at' => now(),
        ]);
        $projectCylinder->users()->attach($userSuryaOperator->id, [
            'role' => 'operator',
            'joined_at' => now(),
        ]);

        // Create projects for PT ABC Logistics
        $projectVehicle = Project::create([
            'organization_id' => $orgABC->id,
            'template_id' => $templateVehicle->id,
            'name' => 'Vehicle Tracker',
            'slug' => 'vehicle-tracker',
            'description' => 'Track fleet vehicles',
            'status' => 'active',
            'settings' => [
                'enable_gps' => true,
                'enable_maintenance' => true,
            ],
        ]);

        // Attach users to Vehicle project
        $projectVehicle->users()->attach($userABCAdmin->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);
        $projectVehicle->users()->attach($userABCManager->id, [
            'role' => 'manager',
            'joined_at' => now(),
        ]);

        // Create projects for PT XYZ Manufacturing
        $projectFactory = Project::create([
            'organization_id' => $orgXYZ->id,
            'template_id' => $templateEquipment->id,
            'name' => 'Factory Equipment Tracker',
            'slug' => 'factory-equipment-tracker',
            'description' => 'Track factory equipment and machinery',
            'status' => 'active',
            'settings' => [
                'enable_rfid' => true,
                'enable_gps' => true,
                'enable_maintenance' => true,
                'enable_inspection' => true,
            ],
        ]);

        $projectWarehouse = Project::create([
            'organization_id' => $orgXYZ->id,
            'template_id' => $templateWarehouse->id,
            'name' => 'Warehouse Tracker',
            'slug' => 'warehouse-tracker',
            'description' => 'Track warehouse inventory',
            'status' => 'active',
            'settings' => [
                'enable_rfid' => true,
                'enable_inventory' => true,
            ],
        ]);

        // Attach users to XYZ projects
        $projectFactory->users()->attach($userXYZAdmin->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);
        $projectWarehouse->users()->attach($userXYZAdmin->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        $this->command->info('Projects seeded successfully.');
    }
}
