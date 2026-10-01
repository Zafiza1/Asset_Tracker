<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN-####-????')),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'asset_type' => fake()->randomElement(['equipment', 'vehicle', 'cylinder', 'container']),
            'status' => 'active',
            'metadata' => [],
        ];
    }
}
