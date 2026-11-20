<?php

namespace Tests\Feature;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Internal\AssetInspector;
use App\Domain\Organization\Models\Organization;
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

        $admin = User::query()->where('email', 'admin@sentosagas.test')->firstOrFail();
        $sentosa = Organization::query()->where('code', 'SENTOSA')->firstOrFail();
        $this->actingAs($admin, 'web');
        $this->assertSame(8, $this->widgetData($sentosa, $admin, 'total_assets')['value']);
        $this->assertSame(1, $this->widgetData($sentosa, $admin, 'assets_on_loan')['value']);

        // The member's Dashboard Aset comes from their role template, and every widget answers.
        $board = $this->getJson('/api/dashboards/asset-board')->assertOk()->json('data');
        $this->assertNotEmpty($board['template']);
        $this->assertNotEmpty($board['widgets']);
        foreach ($board['widgets'] as $widget) {
            $this->getJson("/api/dashboards/{$board['id']}/widgets/{$widget['id']}/data")->assertOk();
        }

        // Enough seats for every demo member, so the demo organizations can change data.
        $license = $this->getJson('/api/license')->assertOk()->json('data');
        $this->assertFalse($license['over_limit']);
        $this->assertGreaterThanOrEqual($license['seats']['used'], $license['seats']['capacity']);
    }
}
