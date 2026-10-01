<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetLocationFactory extends Factory
{
    protected $model = AssetLocation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'asset_id' => Asset::factory(),
            'location_id' => Location::factory(),
            'source' => 'manual',
            'metadata' => [],
            'arrived_at' => now(),
        ];
    }
}
