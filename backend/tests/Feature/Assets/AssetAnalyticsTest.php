<?php

namespace Tests\Feature\Assets;

use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\AssetCondition;
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
        $heavy = AssetCondition::query()->where('organization_id', $this->org->id)->where('code', 'RUSAK_BERAT')->value('id');

        $laptop = $this->asset(['name' => 'Laptop', 'category_id' => $it, 'acquisition_cost' => 10000000]);
        $this->asset(['name' => 'Server', 'category_id' => $it, 'acquisition_cost' => 50000000, 'status' => 'in_use']);
        $this->asset(['name' => 'Genset', 'condition_id' => $heavy, 'status' => 'under_maintenance']);
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
        $this->assertSame(['available' => 1, 'in_use' => 1, 'on_loan' => 1, 'under_maintenance' => 1, 'disposed' => 0], $byStatus);

        $this->assertSame(['Kategori 1' => 2, 'Perangkat IT' => 2], collect($data['by_category'])->pluck('count', 'name')->sortKeys()->all());
        $this->assertSame(['Gudang 1' => 3, 'Tanpa lokasi' => 1], collect($data['by_location'])->pluck('count', 'name')->sortKeys()->all());
        $byCondition = collect($data['by_condition'])->pluck('count', 'name')->all();
        $this->assertSame(1, $byCondition['Rusak Berat']);
        $this->assertSame(3, $byCondition['Belum dinilai']);

        $this->assertSame(['overdue_loan' => 1, 'poor_condition' => 1, 'under_maintenance' => 1, 'no_location' => 1], $data['attention']['counts']);
        $this->assertSame('overdue_loan', $data['attention']['items'][0]['reason']);
        $this->assertSame('Laptop', $data['attention']['items'][0]['asset']['name']);
        // Genset is both in poor condition and under maintenance: listed once.
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

    public function test_dashboard_requires_report_permission(): void
    {
        $this->actingAs($this->createTenantUser($this->org, []), 'web');
        $this->assertErrorCode($this->getJson('/api/dashboard'), 403, 'FORBIDDEN');
    }

    /** @param array<string, mixed> $data */
    private function asset(array $data): string
    {
        return $this->postJson('/api/assets', [
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ])->assertCreated()->json('data.id');
    }
}
