<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'code' => 'DP'.fake()->unique()->numerify('####'),
            'name' => 'Departemen '.fake()->word(),
        ];
    }
}
