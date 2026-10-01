<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Integration>
 */
class IntegrationFactory extends Factory
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
            'name' => fake()->company() . ' Integration',
            'type' => fake()->randomElement(['rfid', 'gps', 'ble', 'nfc', 'lorawan', 'iot', 'barcode', 'qr_code', 'external_api']),
            'provider' => fake()->optional()->company(),
            'status' => 'disconnected',
            'metadata' => [],
        ];
    }
}
