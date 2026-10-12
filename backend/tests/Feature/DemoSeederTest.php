<?php

namespace Tests\Feature;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Internal\AssetInspector;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/** The demo data is created through the real services, so it must stay valid as rules evolve. */
class DemoSeederTest extends TestCase
{
    public function test_demo_organizations_get_consistent_assets_and_a_populated_dashboard(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(16, Asset::query()->count());
        foreach (Asset::query()->get() as $asset) {
            $this->assertSame([], app(AssetInspector::class)->verify($asset), $asset->asset_number);
        }

        $this->actingAs(User::query()->where('email', 'admin@sentosagas.test')->firstOrFail(), 'web');
        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertSame(8, $data['summary']['total']);
        $this->assertSame(1, $data['attention']['counts']['overdue_loan']);
    }
}
