<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'name' => fake()->company() . ' ' . fake()->randomElement(['Warehouse', 'Site', 'Depot']),
            'type' => fake()->randomElement(['warehouse', 'factory', 'customer-site', 'office', 'vehicle', 'field']),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'metadata' => [],
        ];
    }
}
