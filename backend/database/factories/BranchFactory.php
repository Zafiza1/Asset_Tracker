<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'code' => 'BR'.fake()->unique()->numerify('####'),
            'name' => 'Cabang '.fake()->city(),
        ];
    }
}
