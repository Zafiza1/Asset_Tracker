<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = [
            [
                'name' => 'PT Surya Inti Gas',
                'slug' => 'pt-surya-inti-gas',
                'description' => 'Industrial gas company',
                'email' => 'info@suryaintigas.com',
                'phone' => '+62-21-12345678',
                'address' => 'Jakarta, Indonesia',
                'status' => 'active',
                'settings' => [
                    'max_projects' => 10,
                    'max_users' => 100,
                ],
            ],
            [
                'name' => 'PT ABC Logistics',
                'slug' => 'pt-abc-logistics',
                'description' => 'Logistics and transportation company',
                'email' => 'info@abclogistics.com',
                'phone' => '+62-21-87654321',
                'address' => 'Surabaya, Indonesia',
                'status' => 'active',
                'settings' => [
                    'max_projects' => 5,
                    'max_users' => 50,
                ],
            ],
            [
                'name' => 'PT XYZ Manufacturing',
                'slug' => 'pt-xyz-manufacturing',
                'description' => 'Manufacturing company',
                'email' => 'info@xyzmanufacturing.com',
                'phone' => '+62-21-11223344',
                'address' => 'Bandung, Indonesia',
                'status' => 'active',
                'settings' => [
                    'max_projects' => 8,
                    'max_users' => 75,
                ],
            ],
        ];

        foreach ($organizations as $organization) {
            Organization::create($organization);
        }

        $this->command->info('Organizations seeded successfully.');
    }
}
