<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class MovementFactory extends Factory
{
    protected $model = Movement::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'asset_id' => Asset::factory(),
            'from_location_id' => null,
            'to_location_id' => Location::factory(),
            'source' => 'manual',
            'recorded_by' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }
}
