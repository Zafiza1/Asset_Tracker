<?php

namespace Database\Factories;

use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['core', 'tracking', 'maintenance', 'inspection', 'custom']),
            'author' => 'Asset Tracker Platform',
            'is_core' => fake()->boolean(30), // 30% chance of being core
            'status' => 'available',
            'metadata' => [],
        ];
    }

    public function core(): self
    {
        return $this->state([
            'is_core' => true,
            'category' => 'core',
        ]);
    }

    public function custom(): self
    {
        return $this->state([
            'is_core' => false,
            'category' => 'custom',
        ]);
    }

    public function deprecated(): self
    {
        return $this->state([
            'status' => 'deprecated',
        ]);
    }
}
