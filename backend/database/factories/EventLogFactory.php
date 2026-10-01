<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventLog>
 */
class EventLogFactory extends Factory
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
            'asset_id' => \App\Models\Asset::factory(),
            'device_id' => \App\Models\Device::factory(),
            'integration_id' => \App\Models\Integration::factory(),
            'event_type' => fake()->randomElement(['asset.created', 'asset.updated', 'asset.location.updated', 'asset.detected']),
            'source' => fake()->randomElement(['api', 'rfid', 'gps', 'webhook']),
            'payload' => [
                'data' => fake()->randomElement([null, ['key' => 'value']]),
            ],
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => fake()->randomElement(['pending', 'processed', 'failed']),
            'metadata' => fake()->randomElement([null, ['source' => 'test']]),
        ];
    }
}
