<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'organization_id' => \App\Models\Organization::factory(),
            'project_id' => \App\Models\Project::factory(),
            'action' => fake()->randomElement(['create', 'update', 'delete', 'view', 'login', 'logout']),
            'resource_type' => fake()->randomElement(['Asset', 'Location', 'Movement', 'Device', 'Integration', 'Project', 'Organization']),
            'resource_id' => fake()->randomNumber(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'metadata' => [
                'description' => fake()->sentence(),
                'changes' => fake()->randomElement([null, ['field' => 'value']]),
            ],
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
