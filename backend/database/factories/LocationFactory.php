<?php

namespace Database\Factories;

use App\Domain\GenericMaster\Models\LocationType;
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
            'location_type_id' => fn () => LocationType::query()->value('id')
                ?? LocationType::query()->create(['code' => 'GUDANG', 'name' => 'Gudang'])->id,
        ];
    }
}
