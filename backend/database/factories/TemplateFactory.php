<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['general', 'industrial', 'warehouse', 'vehicle', 'equipment']),
            'version' => '1.0.0',
            'status' => 'available',
            'default_modules' => [],
            'default_settings' => [],
            'metadata' => [],
        ];
    }

    public function general(): self
    {
        return $this->state([
            'name' => 'General Asset Tracker',
            'slug' => 'general-asset-tracker',
            'category' => 'general',
            'description' => 'A general-purpose asset tracking template suitable for various industries.',
        ]);
    }

    public function industrial(): self
    {
        return $this->state([
            'name' => 'Industrial Asset Tracker',
            'slug' => 'industrial-asset-tracker',
            'category' => 'industrial',
            'description' => 'Template for tracking industrial equipment and machinery.',
        ]);
    }

    public function warehouse(): self
    {
        return $this->state([
            'name' => 'Warehouse Asset Tracker',
            'slug' => 'warehouse-asset-tracker',
            'category' => 'warehouse',
            'description' => 'Template optimized for warehouse inventory and logistics.',
        ]);
    }

    public function vehicle(): self
    {
        return $this->state([
            'name' => 'Vehicle Tracker',
            'slug' => 'vehicle-tracker',
            'category' => 'vehicle',
            'description' => 'Template for tracking fleet vehicles and mobile assets.',
        ]);
    }

    public function equipment(): self
    {
        return $this->state([
            'name' => 'Equipment Tracker',
            'slug' => 'equipment-tracker',
            'category' => 'equipment',
            'description' => 'Template for tracking heavy equipment and machinery.',
        ]);
    }

    public function deprecated(): self
    {
        return $this->state([
            'status' => 'deprecated',
        ]);
    }
}
