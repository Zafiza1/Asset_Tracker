<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecurityLog>
 */
class SecurityLogFactory extends Factory
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
            'event_type' => fake()->randomElement([
                'login_failed',
                'login_success',
                'logout',
                'password_changed',
                'api_key_used',
                'suspicious_activity',
                'rate_limit_exceeded',
                'unauthorized_access',
            ]),
            'severity' => fake()->randomElement(['low', 'medium', 'high']),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'details' => [
                'description' => fake()->sentence(),
                'attempts' => fake()->randomNumber(),
            ],
            'is_suspicious' => fake()->boolean(10),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
