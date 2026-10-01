<?php

namespace Database\Factories;

use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateVersionFactory extends Factory
{
    protected $model = TemplateVersion::class;

    public function definition(): array
    {
        return [
            'template_id' => Template::factory(),
            // Unique per factory call: (template_id, version) is a unique key.
            'version' => fake()->unique()->numerify('#.#.##'),
            'description' => fake()->sentence(),
            'default_modules' => [],
            'default_settings' => [],
            'metadata' => [],
            'status' => 'available',
            'released_at' => now(),
        ];
    }

    public function released(): self
    {
        return $this->state([
            'released_at' => now()->subDays(fake()->numberBetween(1, 365)),
        ]);
    }

    public function unreleased(): self
    {
        return $this->state([
            'released_at' => null,
        ]);
    }

    public function deprecated(): self
    {
        return $this->state([
            'status' => 'deprecated',
        ]);
    }

    public function withVersion(string $version): self
    {
        return $this->state([
            'version' => $version,
        ]);
    }
}
