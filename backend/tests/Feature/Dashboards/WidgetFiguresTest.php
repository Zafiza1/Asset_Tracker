<?php

namespace Tests\Feature\Dashboards;

use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dashboard Aset widgets (F-06 §4.3): the figures equal what is stored, per tenant. Replaces the
 * checks of the fixed GET /api/dashboard removed in F-06f, on the same data.
 */
class WidgetFiguresTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_widgets_are_empty_for_a_new_organization(): void
    {
        $this->assertSame(0, $this->widget('total_assets')['value']);
        $this->assertSame([], $this->widget('assets_by_category')['categories']);
        $added = $this->widget('assets_added_monthly');
        $this->assertCount(12, $added['categories']);
        $this->assertSame(0, array_sum($added['series'][0]['data']));
        $this->assertSame(0, $this->widget('recent_transactions')['count']);
        $this->assertNull($this->widget('data_completeness')['value']);
    }

    public function test_widget_figures_match_the_stored_data(): void
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

        // Archived assets leave the inventory unless the widget asks for them.
        $this->assertSame(4, $this->widget('total_assets')['value']);
        $this->assertSame(5, $this->widget('total_assets', ['include_archived' => true])['value']);
        $this->assertEquals(60000000, (float) $this->widget('total_value')['value']);
        $this->assertSame(2, $this->widget('active_assets')['value']);   // server in use, laptop on loan
        $this->assertSame(2, $this->widget('inactive_assets')['value']); // available + under maintenance
        $this->assertSame(1, $this->widget('assets_on_loan')['value']);
        $this->assertSame(1, $this->widget('assets_under_maintenance')['value']);

        $byStatus = array_column($this->widget('assets_by_status')['items'], 'count', 'status');
        $this->assertSame(['in_use' => 1, 'available' => 1, 'under_maintenance' => 1, 'on_loan' => 1, 'off_site' => 0, 'lost' => 0, 'disposed' => 0], $byStatus);
        // The status breakdown adds up to "Total aset" (every status, archived left out).
        $this->assertSame(4, array_sum($byStatus));

        $byCategory = $this->widget('assets_by_category');
        $this->assertSame(['Kategori 1' => 2, 'Perangkat IT' => 2], array_combine($byCategory['categories'], $byCategory['series'][0]['data']));
        $this->assertSame(['Gudang 1' => 3, 'Tanpa lokasi' => 1], collect($this->widget('assets_by_location')['items'])->pluck('count', 'name')->sortKeys()->all());

        // The asset without a location is listed as incomplete.
        $incomplete = collect($this->widget('incomplete_assets_list')['rows'])->firstWhere('asset.name', 'Tanpa lokasi');
        $this->assertContains('Lokasi', $incomplete['missing'] ?? []);

        // 5 registrations + loan + deactivation; the deactivation is the latest.
        $recent = $this->widget('recent_transactions');
        $this->assertSame(7, $recent['count']);
        $this->assertSame(TransactionType::LABELS[TransactionType::ARCHIVE], $recent['items'][0]['type']);
        $monthly = $this->widget('transactions_monthly');
        $this->assertSame(7, array_sum(array_map('array_sum', array_column($monthly['series'], 'data'))));
        $this->assertSame(5, array_sum(collect($monthly['series'])->firstWhere('key', TransactionType::REGISTRATION)['data']));

        $added = $this->widget('assets_added_monthly');
        $this->assertSame(now('Asia/Jakarta')->format('Y-m'), end($added['categories']));
        $this->assertSame(5, end($added['series'][0]['data']));
    }

    public function test_widget_insights_match_the_stored_data(): void
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

        // 5 operable assets, 3 in service (server in use, laptop + printer on loan).
        $this->assertSame(3, $this->widget('active_assets')['value']);
        $this->assertSame(2, $this->widget('inactive_assets')['value']);
        $this->assertSame(2, $this->widget('assets_on_loan')['value']);
        // Due within 7 days: the printer; the laptop is already overdue.
        $dueSoon = $this->widget('due_soon_list');
        $this->assertSame(1, $dueSoon['count']);
        $this->assertSame('Printer', $dueSoon['rows'][0]['asset']['name']);

        // < 1, 1–3, 3–5, 5–10, ≥ 10 years; assets without an acquisition date are left out.
        $this->assertSame([1, 1, 0, 1, 0], $this->widget('asset_age')['series'][0]['data']);

        $value = $this->widget('value_by_category');
        $this->assertSame(['Perangkat IT', 'Kategori 1'], $value['categories']);
        $this->assertEquals([60000000, 3000000], $value['series'][0]['data']);

        // 5 assets × (5 optional fields + required attributes): location 4, acquisition date 3,
        // cost 3, serial 5, photo 0, no required attribute missing 5 → 20 of 30 slots.
        $this->assertEquals(66.7, $this->widget('data_completeness')['value']);

        // Each incomplete field links to the asset list filtered to the assets missing it.
        $this->assertSame(['Genset'], array_column($this->getJson('/api/assets?missing=location_id')->assertOk()->json('data'), 'name'));
        $this->assertCount(2, $this->getJson('/api/assets?missing=acquisition_date')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/assets?missing=serial_number')->assertOk()->json('data'));
        $this->getJson('/api/assets?missing=name')->assertUnprocessable();

        $idleList = $this->widget('idle_assets_list', ['days' => 90]);
        $this->assertSame(1, $idleList['count']);
        $this->assertSame($idle, $idleList['rows'][0]['asset']['id']);
        $this->assertGreaterThanOrEqual(119, $idleList['rows'][0]['idle_days']);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function widget(string $key, array $configuration = []): array
    {
        return $this->widgetData($this->org, $this->admin, $key, $configuration);
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
