<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            ModuleSeeder::class,
            TemplateSeeder::class,
            DeviceTypeSeeder::class,
            OrganizationSeeder::class,
            UserSeeder::class,
            ProjectSeeder::class,
            ProjectModuleSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
