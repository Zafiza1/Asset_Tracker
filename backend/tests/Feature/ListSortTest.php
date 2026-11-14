<?php

namespace Tests\Feature;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Tests\TestCase;

/** Every column of every list table can be sorted both ways (`?sort=key` / `?sort=-key`). */
class ListSortTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    /** List endpoint => the sort keys its table's columns use. */
    private const SORTS = [
        '/api/assets' => ['name', 'serial_number', 'category', 'location', 'status', 'acquisition_date', 'acquisition_cost', 'created_at'],
        '/api/reports/assets' => ['name', 'serial_number', 'category', 'location', 'status', 'acquisition_date', 'acquisition_cost'],
        '/api/transactions' => ['number', 'date', 'asset', 'type', 'by'],
        '/api/approvals' => ['action', 'category', 'requester', 'decider', 'status', 'created_at'],
        '/api/audit-logs' => ['time', 'actor', 'action', 'module', 'object'],
        '/api/users' => ['name', 'roles', 'job', 'status', 'login'],
        '/api/invitations' => ['email', 'roles', 'status', 'invited_by', 'expires'],
        '/api/license-codes' => ['code', 'status', 'holder', 'validity', 'assigned_at'],
        '/api/departments' => ['code', 'name', 'status'],
        '/api/locations' => ['name', 'code', 'address', 'usage', 'status'],
        '/api/tenant-masters/categories' => ['name', 'code', 'attributes', 'description', 'status'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->createAssetFixture($this->org, $this->masters, 'B');
        $this->createAssetFixture($this->org, $this->masters, 'A');
        $other = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC-Z', 'name' => 'Zona Akhir']);
        $other->save();
        Asset::query()->where('name', 'Aset A')->update(['location_id' => $other->id]);
        $this->actingAs($this->admin, 'web');
    }

    public function test_every_column_sorts_both_ways(): void
    {
        foreach (self::SORTS as $endpoint => $keys) {
            foreach ($keys as $key) {
                foreach ([$key, "-{$key}"] as $sort) {
                    $this->getJson("{$endpoint}?sort={$sort}")->assertOk();
                }
            }
        }
    }

    public function test_assets_sort_by_related_names_with_empty_values_last(): void
    {
        $names = fn (string $sort) => array_column($this->getJson("/api/assets?sort={$sort}")->assertOk()->json('data'), 'name');

        // "Gudang 1" (Aset B) before "Zona Akhir" (Aset A), and the other way round.
        $this->assertSame(['Aset B', 'Aset A'], $names('location'));
        $this->assertSame(['Aset A', 'Aset B'], $names('-location'));
        $this->assertSame(['Aset A', 'Aset B'], $names('name'));
        $this->assertSame(['Aset B', 'Aset A'], $names('-name'));

        // An asset without a location comes last in both directions.
        Asset::query()->where('name', 'Aset A')->update(['location_id' => null]);
        $this->assertSame(['Aset B', 'Aset A'], $names('location'));
        $this->assertSame(['Aset B', 'Aset A'], $names('-location'));
    }

    public function test_categories_sort_by_name_instead_of_tree(): void
    {
        $second = (new TenantAssetCategory)->forceFill(['organization_id' => $this->org->id, 'code' => 'AAA', 'name' => 'Zebra',
            'generic_category_id' => $this->masters['category']->generic_category_id]);
        $second->save();

        $names = fn (string $sort) => array_column($this->getJson("/api/tenant-masters/categories?sort={$sort}")->assertOk()->json('data'), 'name');
        $this->assertSame(['Kategori 1', 'Zebra'], $names('name'));
        $this->assertSame(['Zebra', 'Kategori 1'], $names('-name'));
        $this->assertSame(['Zebra', 'Kategori 1'], $names('code'));
    }

    public function test_transactions_sort_by_asset_name(): void
    {
        $assets = fn (string $sort) => array_column(array_column($this->getJson("/api/transactions?sort={$sort}")->assertOk()->json('data'), 'asset'), 'name');

        $this->assertSame(['Aset A', 'Aset B'], $assets('asset'));
        $this->assertSame(['Aset B', 'Aset A'], $assets('-asset'));
    }
}
