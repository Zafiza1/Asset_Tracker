<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Asset Management container: registration, listing/filtering, editing, deactivation. */
class AssetManagementTest extends TestCase
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

    public function test_registration_assigns_number_and_records_history_and_audit(): void
    {
        $first = $this->register(['name' => 'Laptop Dell', 'serial_number' => 'SN-001', 'acquisition_cost' => 15000000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.category.code', 'CAT1')
            ->assertJsonPath('data.location.code', 'LOC1')
            ->assertJsonPath('data.is_archived', false)
            ->json('data');
        $second = $this->register(['name' => 'Printer'])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^AST-\d{4}-000001$/', $first['asset_number']);
        $this->assertMatchesRegularExpression('/^AST-\d{4}-000002$/', $second['asset_number']);

        $history = $this->getJson("/api/assets/{$first['id']}/transactions")->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame('registration', $history[0]['type']);
        $this->assertSame('available', $history[0]['to_status']);
        $this->assertSame('LOC1', $history[0]['to_location']['code']);
        $this->assertStringStartsWith('REG-', $history[0]['transaction_number']);
        $this->assertSame($this->admin->name, $history[0]['created_by']);

        $this->assertTrue(AuditLog::query()->where('action', 'asset.created')->where('entity_id', $first['id'])->exists());
    }

    public function test_registration_validates_references_and_initial_status(): void
    {
        $this->assertErrorCode($this->register(['name' => 'X', 'category_id' => '00000000-0000-0000-0000-000000000000']), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->register(['name' => 'X', 'status' => 'on_loan']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->register(['name' => '']), 422, 'VALIDATION_FAILED');

        // Another organization's category is "not found", never usable.
        $mastersB = $this->createMasterFixtures($this->createOrganization('BETA')['organization'], 'B');
        $this->assertErrorCode($this->register(['name' => 'X', 'category_id' => $mastersB['category']->id]), 422, 'INVALID_REFERENCE');
    }

    public function test_internal_identity_is_immutable_and_assets_are_never_deleted(): void
    {
        $id = $this->register(['name' => 'Genset'])->json('data.id');

        $attempts = [
            'immutable' => fn () => DB::table('assets')->where('id', $id)->update(['asset_number' => 'HACK-1']),
            'append-only' => fn () => DB::table('assets')->where('id', $id)->delete(),
        ];
        foreach ($attempts as $expected => $attempt) {
            try {
                // Savepoint, so the failed statement does not abort the test transaction.
                DB::transaction($attempt);
                $this->fail("Expected the database to reject: {$expected}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->getJson("/api/assets/{$id}")->assertOk()->assertJsonPath('data.asset_number', fn ($n) => str_starts_with($n, 'AST-'));
    }

    public function test_list_filters_by_several_categories_including_subcategories_with_search_and_status(): void
    {
        $vehicles = $this->category('KEND', 'Kendaraan');
        $cars = $this->category('MOBIL', 'Mobil', $vehicles);
        $it = $this->category('IT', 'Perangkat IT');
        $this->register(['name' => 'Truk Box', 'category_id' => $vehicles->id]);
        $this->register(['name' => 'Avanza', 'category_id' => $cars->id, 'status' => 'in_use']);
        $this->register(['name' => 'Server Rak', 'category_id' => $it->id]);
        $this->register(['name' => 'Meja', 'category_id' => $this->masters['category']->id]);

        $names = fn (string $query) => collect($this->getJson("/api/assets?{$query}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Avanza', 'Truk Box'], $names("category_ids={$vehicles->id}"));
        $this->assertSame(['Avanza', 'Server Rak', 'Truk Box'], $names("category_ids={$vehicles->id},{$it->id}"));
        $this->assertSame(['Avanza'], $names("category_ids={$vehicles->id}&status=in_use"));
        $this->assertSame(['Server Rak'], $names('search=server'));
        $this->assertSame(['Avanza'], $names("category_ids={$vehicles->id}&search=AVA"));
        $this->assertErrorCode($this->getJson('/api/assets?category_ids=bukan-uuid'), 422, 'VALIDATION_FAILED');
    }

    public function test_edit_descriptive_data_with_optimistic_lock(): void
    {
        $asset = $this->register(['name' => 'Laptop'])->json('data');

        $updated = $this->patchJson("/api/assets/{$asset['id']}", [
            'name' => 'Laptop Kantor', 'lock_version' => $asset['lock_version'],
            // Controlled fields are not editable here; they change through transactions.
            'status' => 'disposed', 'location_id' => null,
        ])->assertOk()->assertJsonPath('data.name', 'Laptop Kantor')->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.location.code', 'LOC1')->json('data');
        $this->assertSame($asset['lock_version'] + 1, $updated['lock_version']);

        $this->assertErrorCode($this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Basi', 'lock_version' => $asset['lock_version']]), 409, 'STALE_ASSET');

        $audit = AuditLog::query()->where('action', 'asset.updated')->firstOrFail();
        $this->assertSame(['name' => 'Laptop'], $audit->before);
        $this->assertSame(['name' => 'Laptop Kantor'], $audit->after);
    }

    public function test_serial_number_is_unique_per_organization_case_insensitive(): void
    {
        $this->register(['name' => 'A', 'serial_number' => 'ABC-1'])->assertCreated();
        $this->assertErrorCode($this->register(['name' => 'B', 'serial_number' => 'abc-1']), 422, 'VALIDATION_FAILED');

        ['organization' => $orgB, 'admin' => $adminB] = $this->createOrganization('BETA');
        $mastersB = $this->createMasterFixtures($orgB, 'B');
        $this->actingAs($adminB, 'web');
        $this->postJson('/api/assets', ['name' => 'C', 'serial_number' => 'ABC-1', 'category_id' => $mastersB['category']->id])->assertCreated();
    }

    public function test_deactivate_and_reactivate_keep_number_and_history(): void
    {
        $asset = $this->register(['name' => 'Proyektor'])->json('data');

        $this->postJson("/api/assets/{$asset['id']}/archive", ['reason' => 'Tidak dipakai lagi'])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->assertSame([], $this->getJson('/api/assets')->json('data'));
        $this->assertCount(1, $this->getJson('/api/assets?archived=only')->json('data'));
        $this->assertErrorCode($this->patchJson("/api/assets/{$asset['id']}", ['name' => 'X']), 409, 'ASSET_ARCHIVED');
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_id' => $asset['id'], 'type' => 'note', 'notes' => 'cek']), 409, 'ASSET_ARCHIVED');
        $this->assertErrorCode($this->postJson("/api/assets/{$asset['id']}/archive"), 409, 'ASSET_ARCHIVED');

        $this->postJson("/api/assets/{$asset['id']}/restore")->assertOk()->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.asset_number', $asset['asset_number']);

        $history = collect($this->getJson("/api/assets/{$asset['id']}/transactions")->json('data'));
        $this->assertSame(['restore', 'archive', 'registration'], $history->pluck('type')->all());
        $this->assertSame('Tidak dipakai lagi', $history[1]['notes']);
    }

    public function test_masters_used_by_active_assets_cannot_be_archived(): void
    {
        $asset = $this->register(['name' => 'Kursi'])->json('data');

        $this->assertErrorCode($this->postJson("/api/tenant-masters/categories/{$this->masters['category']->id}/archive"), 409, 'CATEGORY_IN_USE');
        $this->assertErrorCode($this->postJson("/api/locations/{$this->masters['location']->id}/archive"), 409, 'LOCATION_IN_USE');

        // Once the asset is deactivated the location is free again; history keeps its reference.
        $this->postJson("/api/assets/{$asset['id']}/archive")->assertOk();
        $this->postJson("/api/locations/{$this->masters['location']->id}/archive")->assertOk();
    }

    public function test_root_category_without_generic_anchor_uses_the_general_one(): void
    {
        $category = $this->postJson('/api/tenant-masters/categories', ['code' => 'ALAT', 'name' => 'Peralatan'])->assertCreated()->json('data');

        $this->assertSame(GenericAssetCategory::query()->where('code', 'UMUM')->value('id'), $category['generic_category_id']);
    }

    /** @param array<string, mixed> $data */
    private function register(array $data)
    {
        return $this->postJson('/api/assets', [
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ]);
    }

    private function category(string $code, string $name, ?TenantAssetCategory $parent = null): TenantAssetCategory
    {
        $id = $this->postJson('/api/tenant-masters/categories', ['code' => $code, 'name' => $name, 'parent_id' => $parent?->id])->assertCreated()->json('data.id');

        return TenantAssetCategory::query()->findOrFail($id);
    }

    public function test_asset_model_hides_other_tenants(): void
    {
        $orgB = $this->createOrganization('BETA')['organization'];
        $foreign = $this->createAssetFixture($orgB, $this->createMasterFixtures($orgB, 'B'))['asset'];

        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->assertSame(0, collect($this->getJson('/api/assets')->json('data'))->where('id', $foreign->id)->count());
        $this->assertSame(1, Asset::query()->whereKey($foreign->id)->count(), 'fixture exists in system context');
    }
}
