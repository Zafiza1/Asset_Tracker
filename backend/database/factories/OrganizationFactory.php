<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return ['name' => fake()->company()];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Organization $org) {
            $org->code ??= 'ORG'.strtoupper(fake()->unique()->bothify('??##'));
            $org->status ??= Organization::STATUS_ACTIVE;
        });
    }
}
