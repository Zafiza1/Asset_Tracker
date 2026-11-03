<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'code' => 'LOC'.fake()->unique()->numerify('####'),
            'name' => 'Lokasi '.fake()->streetName(),
        ];
    }
}
