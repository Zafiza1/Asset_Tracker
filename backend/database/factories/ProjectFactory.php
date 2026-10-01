<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'template_id' => Template::factory(),
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'status' => 'active',
            'settings' => [
                'timezone' => 'UTC',
            ],
            'starts_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'ends_at' => fake()->dateTimeBetween('now', '+5 years'),
        ];
    }
}
