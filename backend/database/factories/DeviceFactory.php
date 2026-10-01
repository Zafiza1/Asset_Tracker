<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => \App\Models\Organization::factory(),
            'project_id' => \App\Models\Project::factory(),
            'device_type_id' => \App\Models\DeviceType::factory(),
            'integration_id' => \App\Models\Integration::factory(),
            'serial_number' => fake()->bothify('???-###'),
            'name' => fake()->words(2, true),
            'status' => fake()->randomElement(['online', 'offline']),
            'metadata' => [],
        ];
    }
}
