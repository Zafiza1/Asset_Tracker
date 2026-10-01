<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PermissionFactory extends Factory
{
    public function definition(): array
    {
        $modules = ['asset', 'location', 'movement', 'device', 'integration', 'user', 'role', 'permission', 'organization', 'project', 'module', 'template', 'report', 'audit'];
        $actions = ['view', 'create', 'update', 'delete', 'manage', 'configure', 'install', 'enable', 'disable'];
        
        $module = fake()->randomElement($modules);
        $action = fake()->randomElement($actions);
        
        return [
            'name' => ucfirst($action) . ' ' . ucfirst($module),
            'slug' => $module . '.' . $action,
            'module' => $module,
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }

    public function system(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    public function forModule(string $module): self
    {
        return $this->state(fn (array $attributes) => [
            'module' => $module,
        ]);
    }
}
