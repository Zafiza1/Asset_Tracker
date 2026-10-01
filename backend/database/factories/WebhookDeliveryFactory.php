<?php

namespace Database\Factories;

use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event_log_id' => null,
            'event_type' => fake()->randomElement(['asset.created', 'asset.updated', 'asset.deleted', 'asset.location.updated', 'asset.status.changed']),
            'payload' => [
                'event' => fake()->randomElement(['asset.created', 'asset.updated']),
                'timestamp' => now()->toIso8601String(),
                'data' => [
                    'id' => fake()->randomNumber(),
                ],
            ],
            'status' => fake()->randomElement(['pending', 'delivered', 'failed', 'retrying']),
            'attempt' => fake()->numberBetween(0, 3),
            'max_attempts' => 3,
            'attempt_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            'delivered_at' => fake()->optional(0.7)->dateTimeBetween('-1 hour', 'now'),
            'response_status' => fake()->optional(0.7)->numberBetween(200, 500),
            'response_body' => fake()->optional(0.7)->text(),
            'error_message' => fake()->optional(0.3)->sentence(),
            'metadata' => null,
        ];
    }
}
