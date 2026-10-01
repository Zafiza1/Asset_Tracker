<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'slug' => fake()->slug(),
            'description' => fake()->sentence(),
            'level' => fake()->numberBetween(0, 100),
            'is_system' => false,
        ];
    }

    public function system(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    public function platformAdmin(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Platform Admin',
            'slug' => 'platform-admin',
            'description' => 'Full platform administrator access',
            'level' => 100,
            'is_system' => true,
        ]);
    }

    public function organizationOwner(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Organization Owner',
            'slug' => 'organization-owner',
            'description' => 'Full organization access',
            'level' => 90,
            'is_system' => true,
        ]);
    }

    public function projectAdmin(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Project Admin',
            'slug' => 'project-admin',
            'description' => 'Full project access',
            'level' => 80,
            'is_system' => true,
        ]);
    }

    public function manager(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Manager',
            'slug' => 'manager',
            'description' => 'Manage assets and operations',
            'level' => 60,
            'is_system' => true,
        ]);
    }

    public function operator(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Operator',
            'slug' => 'operator',
            'description' => 'Operational access',
            'level' => 40,
            'is_system' => true,
        ]);
    }

    public function viewer(): self
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Viewer',
            'slug' => 'viewer',
            'description' => 'Read-only access',
            'level' => 20,
            'is_system' => true,
        ]);
    }
}
