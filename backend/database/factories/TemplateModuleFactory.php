<?php

namespace Database\Factories;

use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateModule;
use App\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class TemplateModuleFactory extends Factory
{
    protected $model = TemplateModule::class;

    public function definition(): array
    {
        return [
            'template_version_id' => TemplateVersion::factory(),
            'module_id' => Module::factory(),
            'version_constraint' => null,
            'required' => true,
            'default_config' => [],
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function required(): self
    {
        return $this->state([
            'required' => true,
        ]);
    }

    public function optional(): self
    {
        return $this->state([
            'required' => false,
        ]);
    }

    public function withVersionConstraint(string $constraint): self
    {
        return $this->state([
            'version_constraint' => $constraint,
        ]);
    }
}
