<?php

namespace Tests\Feature\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Services\AssetService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Assigned by the database trigger and kept internal: the API never exposes it.
        $this->assertArrayNotHasKey('asset_number', $first);
        $this->assertMatchesRegularExpression('/^AST-\d{4}-000001$/', DB::table('assets')->where('id', $first['id'])->value('asset_number'));
        $this->assertMatchesRegularExpression('/^AST-\d{4}-000002$/', DB::table('assets')->where('id', $second['id'])->value('asset_number'));

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
        $this->assertStringStartsWith('AST-', DB::table('assets')->where('id', $id)->value('asset_number'));
    }

    public function test_database_trigger_assigns_the_asset_number_whatever_the_insert_sends(): void
    {
        DB::table('organization_settings')->where('organization_id', $this->org->id)->update(['asset_number_format' => 'INV/{YY}{MM}/{SEQ:3}']);
        $first = $this->register(['name' => 'Kursi'])->json('data.id');
        $second = $this->register(['name' => 'Meja'])->json('data.id');

        // A row written straight to the table cannot pick its own number either.
        $direct = (string) Str::uuid();
        DB::table('assets')->insert([
            'id' => $direct, 'organization_id' => $this->org->id, 'asset_number' => 'MANUAL-1', 'name' => 'Lemari',
            'category_id' => $this->masters['category']->id, 'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $period = now($this->org->timezone)->format('ym');
        $numbers = DB::table('assets')->whereIn('id', [$first, $second, $direct])->pluck('asset_number', 'id');
        $this->assertSame("INV/{$period}/001", $numbers[$first]);
        $this->assertSame("INV/{$period}/002", $numbers[$second]);
        $this->assertSame("INV/{$period}/003", $numbers[$direct]);
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

    public function test_list_filters_by_acquisition_date_range_and_pages_in_a_stable_order(): void
    {
        $this->register(['name' => 'Lama', 'acquisition_date' => '2020-01-15']);
        $this->register(['name' => 'Tengah', 'acquisition_date' => '2022-06-01']);
        $this->register(['name' => 'Baru', 'acquisition_date' => '2024-03-10']);
        $this->register(['name' => 'Tanpa tanggal']);

        $names = fn (string $query) => collect($this->getJson("/api/assets?{$query}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Baru', 'Tengah'], $names('acquired_from=2022-06-01'));
        $this->assertSame(['Lama', 'Tengah'], $names('acquired_to=2022-06-01'));
        $this->assertSame(['Tengah'], $names('acquired_from=2021-01-01&acquired_to=2023-12-31'));
        $this->assertErrorCode($this->getJson('/api/assets?acquired_from=2024-01-01&acquired_to=2023-01-01'), 422, 'VALIDATION_FAILED');

        // Same status for all: pages split by the id tie-breaker never repeat an asset.
        $paged = [...$this->getJson('/api/assets?sort=status&per_page=2&page=1')->json('data'), ...$this->getJson('/api/assets?sort=status&per_page=2&page=2')->json('data')];
        $this->assertCount(4, array_unique(array_column($paged, 'id')));
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

    public function test_bulk_edit_applies_only_the_sent_fields_to_all_assets_or_none(): void
    {
        $a = $this->register(['name' => 'Laptop', 'description' => 'lama'])->json('data');
        $b = $this->register(['name' => 'Printer', 'acquisition_cost' => 500])->json('data');
        $other = $this->category('CAT9', 'Elektronik');

        $this->postJson('/api/assets/bulk-update', [
            'asset_ids' => [$a['id'], $b['id']],
            'changes' => ['category_id' => $other->id, 'description' => 'Pengadaan 2026', 'name' => 'diabaikan'],
        ])->assertOk()->assertJsonPath('data.updated', 2);

        foreach ([$a, $b] as $before) {
            $after = $this->getJson("/api/assets/{$before['id']}")->assertOk()->json('data');
            $this->assertSame('CAT9', $after['category']['code']);
            $this->assertSame('Pengadaan 2026', $after['description']);
            $this->assertSame($before['name'], $after['name']);
            $this->assertSame($before['acquisition_cost'], $after['acquisition_cost']);
        }
        $this->assertSame(2, AuditLog::query()->where('action', 'asset.updated')->count());

        // Category fields: only the keys sent change; unknown keys are ignored.
        $field = $this->postJson("/api/tenant-masters/categories/{$other->id}/attributes", ['label' => 'Warna Casing', 'data_type' => 'string'])
            ->assertCreated()->json('data.key');
        $this->postJson('/api/assets/bulk-update', [
            'asset_ids' => [$a['id'], $b['id']], 'changes' => ['attributes' => [$field => 'Dell', 'tidak_ada' => 'x']],
        ])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame('Dell', $this->getJson("/api/assets/{$b['id']}")->json("data.attributes.{$field}"));
        $this->assertSame('Pengadaan 2026', Asset::query()->findOrFail($b['id'])->description);

        // One archived asset fails the whole batch.
        $this->postJson("/api/assets/{$b['id']}/archive")->assertOk();
        $this->assertErrorCode($this->postJson('/api/assets/bulk-update', [
            'asset_ids' => [$a['id'], $b['id']], 'changes' => ['description' => 'baru'],
        ]), 422, 'BULK_UPDATE_FAILED');
        $this->assertSame('Pengadaan 2026', Asset::query()->findOrFail($a['id'])->description);

        $this->assertErrorCode($this->postJson('/api/assets/bulk-update', ['asset_ids' => [$a['id']], 'changes' => ['name' => 'X']]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->postJson('/api/assets/bulk-update', ['asset_ids' => ['00000000-0000-0000-0000-000000000000'], 'changes' => ['description' => 'X']]), 422, 'INVALID_REFERENCE');
    }

    public function test_serial_number_is_unique_per_organization_case_insensitive(): void
    {
        $this->register(['name' => 'A', 'serial_number' => 'ABC-1'])->assertCreated();
        $this->assertErrorCode($this->register(['name' => 'B', 'serial_number' => 'abc-1']), 422, 'VALIDATION_FAILED');

        ['organization' => $orgB, 'admin' => $adminB] = $this->createOrganization('BETA');
        $mastersB = $this->createMasterFixtures($orgB, 'B');
        $this->actingAs($adminB, 'web');
        $this->postJson('/api/assets', ['name' => 'C', 'serial_number' => 'ABC-1', 'category_id' => $mastersB['category']->id, 'location_id' => $mastersB['location']->id])->assertCreated();
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
            ->assertJsonPath('data.id', $asset['id']);

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

    public function test_barcode_is_a_default_yes_no_field_switched_per_asset(): void
    {
        $it = $this->category('IT', 'Perangkat IT');
        $laptops = $this->category('LAPTOP', 'Laptop', $it);
        $field = collect($this->getJson("/api/tenant-masters/categories/{$laptops->id}/attributes")->assertOk()->json('data'))->firstWhere('label', 'Barcode');
        $this->assertSame(['boolean', 'basic_information', true], [$field['data_type'], $field['section'], $field['is_default']]);

        // Barcode module off (the default): the field is kept, but no asset has a barcode.
        $with = $this->register(['name' => 'ThinkPad', 'category_id' => $laptops->id, 'attributes' => [$field['key'] => true]])
            ->assertCreated()->assertJsonPath('data.barcode_enabled', false)->json('data');
        $this->getJson("/api/assets/{$with['id']}")->assertOk()->assertJsonPath("data.attributes.{$field['key']}", true);

        $this->putJson('/api/modules/barcode', ['enabled' => true])->assertOk();
        $this->getJson("/api/assets/{$with['id']}")->assertOk()->assertJsonPath('data.barcode_enabled', true);
        $without = $this->register(['name' => 'MacBook', 'category_id' => $laptops->id])->assertCreated()->assertJsonPath('data.barcode_enabled', false)->json('data');

        $listed = collect($this->getJson('/api/assets')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($listed[$with['id']]['barcode_enabled']);
        $this->assertFalse($listed[$without['id']]['barcode_enabled']);

        $this->patchJson("/api/assets/{$with['id']}", ['attributes' => [$field['key'] => false], 'lock_version' => $with['lock_version']])
            ->assertOk()->assertJsonPath('data.barcode_enabled', false);
        $this->assertErrorCode($this->patchJson("/api/assets/{$without['id']}", ['attributes' => [$field['key'] => 'ya']]), 422, 'VALIDATION_FAILED');
    }

    public function test_timeline_merges_transactions_and_data_edits_newest_first(): void
    {
        $asset = $this->register(['name' => 'Laptop', 'acquisition_cost' => 1000])->assertCreated()->json('data');
        $other = $this->category('CAT9', 'Elektronik');
        $this->travel(1)->minutes();
        $this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Laptop Dell', 'acquisition_cost' => 2500, 'category_id' => $other->id])->assertOk();
        // An edit that changes nothing leaves no entry.
        $this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Laptop Dell'])->assertOk();
        $location = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC2', 'name' => 'Kantor']);
        $location->save();
        $this->travel(1)->minutes();
        $this->postJson('/api/transactions', ['asset_id' => $asset['id'], 'type' => 'transfer', 'to_location_id' => $location->id])->assertCreated();

        $events = $this->getJson("/api/assets/{$asset['id']}/timeline")->assertOk()->json('data');

        $this->assertSame(['transaction', 'data_change', 'transaction'], array_column($events, 'kind'));
        $this->assertSame('transfer', $events[0]['transaction']['type']);
        $this->assertSame('Kantor', $events[0]['transaction']['to_location']['name']);
        $this->assertSame('registration', $events[2]['transaction']['type']);
        $this->assertSame($this->admin->name, $events[1]['actor']);
        $changes = collect($events[1]['changes'])->keyBy('field');
        $this->assertSame(['Laptop', 'Laptop Dell'], [$changes['name']['before'], $changes['name']['after']]);
        $this->assertSame('Harga perolehan', $changes['acquisition_cost']['label']);
        $this->assertSame(['Kategori 1', 'Elektronik'], [$changes['category_id']['before'], $changes['category_id']['after']]);

        // Without transaction.view only the data edits are shown.
        $role = $this->createRole($this->org, 'ASSET_ONLY', ['organization.view', 'master.tenant.view', 'asset.view'])->code;
        $this->flushSession();
        $this->actingAs($this->createTenantUser($this->org, [$role]), 'web');
        $this->assertSame(['data_change'], array_column($this->getJson("/api/assets/{$asset['id']}/timeline")->assertOk()->json('data'), 'kind'));
    }

    /** @param array<string, mixed> $data */
    private function register(array $data)
    {
        $this->relaxDefaultCondition();

        return $this->postJson('/api/assets', [
            'status' => 'available',
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ]);
    }

    private function category(string $code, string $name, ?TenantAssetCategory $parent = null): TenantAssetCategory
    {
        $id = $this->postJson('/api/tenant-masters/categories', ['code' => $code, 'name' => $name, 'parent_id' => $parent?->id])->assertCreated()->json('data.id');
        $this->relaxDefaultCondition();

        return TenantAssetCategory::query()->findOrFail($id);
    }

    public function test_image_upload_replace_and_remove(): void
    {
        $disk = Storage::fake(AssetService::IMAGE_DISK);
        $id = $this->register(['name' => 'Kamera'])->json('data.id');
        $this->assertNull($this->getJson("/api/assets/{$id}")->json('data.image_url'));
        $this->getJson("/api/assets/{$id}/image")->assertNotFound();

        $first = $this->post("/api/assets/{$id}/image", ['image' => UploadedFile::fake()->image('a.jpg', 40, 40)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.lock_version', 2)->json('data.image_url');
        $this->assertStringStartsWith("/api/assets/{$id}/image?v=", $first);
        $firstPath = Asset::query()->findOrFail($id)->image_path;
        $disk->assertExists($firstPath);
        $this->get("/api/assets/{$id}/image")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Replacing deletes the old file and changes the URL (cache buster).
        $second = $this->post("/api/assets/{$id}/image", ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])
            ->assertOk()->json('data.image_url');
        $this->assertNotSame($first, $second);
        $disk->assertMissing($firstPath);

        $secondPath = Asset::query()->findOrFail($id)->image_path;
        $this->deleteJson("/api/assets/{$id}/image")->assertOk()->assertJsonPath('data.image_url', null);
        $disk->assertMissing($secondPath);
        $this->assertTrue(AuditLog::query()->where('action', 'asset.image_removed')->where('entity_id', $id)->exists());
    }

    public function test_image_upload_accepts_raster_images_only(): void
    {
        Storage::fake(AssetService::IMAGE_DISK);
        $id = $this->register(['name' => 'Kamera'])->json('data.id');
        $upload = fn ($file) => $this->post("/api/assets/{$id}/image", ['image' => $file], ['Accept' => 'application/json']);

        $this->assertErrorCode($upload(UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($upload(UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($upload(UploadedFile::fake()->image('big.jpg')->size(6000)), 422, 'VALIDATION_FAILED');
    }

    public function test_asset_model_hides_other_tenants(): void
    {
        $orgB = $this->createOrganization('BETA')['organization'];
        $foreign = $this->createAssetFixture($orgB, $this->createMasterFixtures($orgB, 'B'))['asset'];

        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/assets/{$foreign->id}/image")->assertNotFound();
        $this->assertSame(0, collect($this->getJson('/api/assets')->json('data'))->where('id', $foreign->id)->count());
        $this->assertSame(1, Asset::query()->whereKey($foreign->id)->count(), 'fixture exists in system context');
    }
}
