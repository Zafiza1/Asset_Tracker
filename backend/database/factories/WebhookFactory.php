<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => null,
            'name' => fake()->company() . ' Webhook',
            'endpoint' => fake()->url() . '/webhook',
            'secret' => 'wh_' . fake()->lexify('????????????????????????????'),
            'events' => fake()->randomElements(['asset.created', 'asset.updated', 'asset.deleted', 'asset.location.updated', 'asset.status.changed'], fake()->numberBetween(1, 3)),
            'active' => true,
            'retry_policy' => [
                'max_attempts' => 3,
                'retry_delay' => 60,
                'backoff_multiplier' => 2,
            ],
            'metadata' => [
                'description' => fake()->sentence(),
            ],
        ];
    }
}
