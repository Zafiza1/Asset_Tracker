<?php

namespace Tests\Feature\Assets;

use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Asset Analytics container: dashboard numbers equal what is stored, per tenant. */
class AssetAnalyticsTest extends TestCase
{
    private Organization $org;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($admin, 'web');
    }

    public function test_dashboard_is_empty_for_a_new_organization(): void
    {
        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $data['summary']['total']);
        $this->assertSame([], $data['by_category']);
        $this->assertCount(12, $data['trend']);
        $this->assertSame(0, array_sum(array_column($data['trend'], 'added')));
        $this->assertSame([], $data['attention']['items']);
    }

    public function test_dashboard_figures_match_the_stored_data(): void
    {
        $it = $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->json('data.id');

        $laptop = $this->asset(['name' => 'Laptop', 'category_id' => $it, 'acquisition_cost' => 10000000]);
        $this->asset(['name' => 'Server', 'category_id' => $it, 'acquisition_cost' => 50000000, 'status' => 'in_use']);
        $this->asset(['name' => 'Genset', 'status' => 'under_maintenance']);
        $this->asset(['name' => 'Tanpa lokasi', 'location_id' => null]);
        $retired = $this->asset(['name' => 'Arsip', 'acquisition_cost' => 999]);
        $this->postJson("/api/assets/{$retired}/archive")->assertOk();

        // Overdue loan: lent 10 days ago, due 3 days ago.
        $this->postJson('/api/transactions', ['asset_id' => $laptop, 'type' => 'loan', 'borrower_name' => 'Budi',
            'transaction_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDays(3)->toDateString()])->assertCreated();

        // Another tenant's data never appears.
        ['organization' => $orgB] = $this->createOrganization('BETA');
        $this->createAssetFixture($orgB, $this->createMasterFixtures($orgB, 'B'));

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(4, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['archived']);
        $this->assertEquals(60000000, (float) $data['summary']['total_value']);
        $this->assertSame(5, $data['summary']['added_this_month']);

        $byStatus = array_column($data['by_status'], 'count', 'status');
        $this->assertSame(['in_use' => 1, 'available' => 1, 'under_maintenance' => 1, 'on_loan' => 1, 'lost' => 0, 'disposed' => 0], $byStatus);
        // The status breakdown adds up to the "Total aset" figure (every status, archived left out).
        $this->assertSame($data['summary']['total'], array_sum($byStatus));
        $this->assertSame($data['summary']['total'], $data['utilization']['operable'] + $data['utilization']['excluded']);

        $this->assertSame(['Kategori 1' => 2, 'Perangkat IT' => 2], collect($data['by_category'])->pluck('count', 'name')->sortKeys()->all());
        $this->assertSame(['Gudang 1' => 3, 'Tanpa lokasi' => 1], collect($data['by_location'])->pluck('count', 'name')->sortKeys()->all());

        $this->assertSame(['overdue_loan' => 1, 'under_maintenance' => 1, 'no_location' => 1], $data['attention']['counts']);
        $this->assertSame('overdue_loan', $data['attention']['items'][0]['reason']);
        $this->assertSame('Laptop', $data['attention']['items'][0]['asset']['name']);
        $this->assertCount(3, $data['attention']['items']);

        $byType = array_column($data['transactions']['by_type'], 'count', 'type');
        $this->assertSame(5, $byType['registration']);
        $this->assertSame(1, $byType['loan']);
        $this->assertSame(1, $byType['archive']);
        $this->assertSame(7, $data['transactions']['total']);
        $this->assertSame('archive', $data['transactions']['recent'][1]['type']);

        $thisMonth = collect($data['trend'])->firstWhere('month', now('Asia/Jakarta')->format('Y-m'));
        $this->assertSame(5, $thisMonth['added']);
        // Loan + deactivation, possibly in different months.
        $this->assertSame(2, array_sum(array_column($data['trend'], 'changes')));
    }

    public function test_dashboard_insights_match_the_stored_data(): void
    {
        $it = $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->json('data.id');

        // Registered, then untouched for 120 days: idle. (Travel forward: the signed database
        // context must not be issued in the past.)
        $idle = $this->asset(['name' => 'Proyektor lama', 'acquisition_date' => now()->subYears(6)->toDateString(), 'acquisition_cost' => 3000000, 'serial_number' => 'SN-1']);
        $this->travel(120)->days();

        $laptop = $this->asset(['name' => 'Laptop', 'category_id' => $it, 'acquisition_cost' => 10000000, 'acquisition_date' => now()->subMonths(6)->toDateString()]);
        $this->asset(['name' => 'Server', 'category_id' => $it, 'acquisition_cost' => 50000000, 'status' => 'in_use', 'acquisition_date' => now()->subYears(2)->toDateString()]);
        $this->asset(['name' => 'Genset', 'status' => 'under_maintenance', 'location_id' => null]);
        $printer = $this->asset(['name' => 'Printer']);

        $this->postJson('/api/transactions', ['asset_id' => $laptop, 'type' => 'loan', 'borrower_name' => 'Budi',
            'transaction_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDays(1)->toDateString()])->assertCreated();
        $this->postJson('/api/transactions', ['asset_id' => $printer, 'type' => 'loan', 'borrower_name' => 'Sari',
            'due_date' => now()->addDays(3)->toDateString()])->assertCreated();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        // 5 operable assets, 3 in service (server in use, laptop + printer on loan).
        $this->assertEquals(['in_service' => 3, 'in_use' => 1, 'on_loan' => 2, 'available' => 1, 'under_maintenance' => 1, 'operable' => 5, 'excluded' => 0, 'rate' => 60], $data['utilization']);
        $this->assertSame(['active' => 2, 'overdue' => 1, 'due_soon' => 1, 'no_due_date' => 0, 'due_soon_days' => 7], $data['loans']);

        $this->assertSame(['lt1' => 1, '1_3' => 1, '3_5' => 0, '5_10' => 1, 'gte10' => 0], array_column($data['age']['buckets'], 'count', 'key'));
        $this->assertSame(2, $data['age']['unknown']);

        // Largest category first; assets without an acquisition date are left out.
        $this->assertSame(['Perangkat IT', 'Kategori 1'], array_column($data['age']['by_category'], 'name'));
        $this->assertSame(['lt1' => 1, '1_3' => 1, '3_5' => 0, '5_10' => 0, 'gte10' => 0], $data['age']['by_category'][0]['buckets']);
        $this->assertSame(2, $data['age']['by_category'][0]['total']);
        $this->assertSame(1, $data['age']['by_category'][1]['total']);
        $this->assertEqualsWithDelta(6.3, $data['age']['by_category'][1]['average_years'], 0.2);

        $this->assertSame(['Proyektor lama', 'Server', 'Laptop'], array_map(fn (array $item): string => $item['asset']['name'], $data['age']['oldest']));
        $this->assertEqualsWithDelta(6.3, $data['age']['oldest'][0]['age_years'], 0.2);

        $this->assertSame(['Perangkat IT', 'Kategori 1'], array_column($data['value_by_category'], 'name'));
        $this->assertEquals(60000000, (float) $data['value_by_category'][0]['value']);

        $quality = array_column($data['data_quality']['fields'], 'filled', 'key');
        $this->assertSame(5, $data['data_quality']['total']);
        $this->assertSame(['location_id' => 4, 'acquisition_date' => 3, 'acquisition_cost' => 3, 'serial_number' => 5, 'image_path' => 0], $quality);
        $this->assertEquals(60, $data['data_quality']['score']);
        // Nobody has a photo, so no asset is complete.
        $this->assertSame(0, $data['data_quality']['complete']);

        // Each incomplete field links to the asset list filtered to the assets missing it.
        $this->assertSame(['Genset'], array_column($this->getJson('/api/assets?missing=location_id')->assertOk()->json('data'), 'name'));
        $this->assertCount(2, $this->getJson('/api/assets?missing=acquisition_date')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/assets?missing=serial_number')->assertOk()->json('data'));
        $this->getJson('/api/assets?missing=name')->assertUnprocessable();

        $this->assertSame(1, $data['idle']['count']);
        $this->assertSame($idle, $data['idle']['items'][0]['asset']['id']);
        $this->assertGreaterThanOrEqual(119, $data['idle']['items'][0]['idle_days']);
    }

    public function test_dashboard_requires_report_permission(): void
    {
        $this->actingAs($this->createTenantUser($this->org, []), 'web');
        $this->assertErrorCode($this->getJson('/api/dashboard'), 403, 'FORBIDDEN');
    }

    /** @param array<string, mixed> $data */
    private function asset(array $data): string
    {
        // Assets are registered with a location now; older ones without one still count as such.
        if (array_key_exists('location_id', $data) && $data['location_id'] === null) {
            $id = $this->asset(array_diff_key($data, ['location_id' => true]));
            DB::table('assets')->where('id', $id)->update(['location_id' => null]);

            return $id;
        }

        $this->relaxDefaultCondition();

        return $this->postJson('/api/assets', [
            'status' => 'available',
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ])->assertCreated()->json('data.id');
    }
}
