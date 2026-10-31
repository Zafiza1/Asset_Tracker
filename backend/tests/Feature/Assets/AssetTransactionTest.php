<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Asset Transactions container: manual transactions, their effect on assets, and the history. */
class AssetTransactionTest extends TestCase
{
    private const OPERATOR = ['organization.view', 'master.tenant.view', 'asset.view', 'asset.status_change', 'transaction.view', 'transaction.create'];

    private const MANAGER = [...self::OPERATOR, 'asset.create', 'asset.transfer', 'asset.dispose', 'report.view'];

    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    private Location $otherLocation;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->otherLocation = $this->location('LOC2', 'Kantor Pusat');
        $this->actingAs($this->admin, 'web');
    }

    public function test_transfer_moves_the_asset_and_records_from_and_to(): void
    {
        $asset = $this->asset();

        $trx = $this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id, 'notes' => 'Pindah ke pusat'])
            ->assertCreated()->assertJsonPath('data.from_location.code', 'LOC1')->assertJsonPath('data.to_location.code', 'LOC2')
            ->assertJsonPath('data.type_label', 'Pemindahan lokasi')->json('data');
        $this->assertStringStartsWith('TRF-', $trx['transaction_number']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.location.code', 'LOC2');

        $this->assertErrorCode($this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->record($asset, 'transfer'), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->record($asset, 'transfer', ['transaction_date' => now()->addDays(2)->toDateString(), 'to_location_id' => $this->masters['location']->id]), 422, 'VALIDATION_FAILED');
    }

    public function test_loan_and_return_cycle(): void
    {
        $asset = $this->asset();

        $this->record($asset, 'loan', ['borrower_name' => 'Budi', 'due_date' => now()->addWeek()->toDateString()])->assertCreated()
            ->assertJsonPath('data.to_status', 'on_loan')->assertJsonPath('data.borrower_name', 'Budi');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'on_loan')->assertJsonPath('data.borrower_name', 'Budi');

        $this->assertErrorCode($this->record($asset, 'loan', ['borrower_name' => 'Ani']), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id]), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->record($asset, 'status_change', ['to_status' => 'in_use']), 409, 'INVALID_ASSET_STATUS');

        $this->record($asset, 'return')->assertCreated()->assertJsonPath('data.to_status', 'available');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available')->assertJsonPath('data.borrower_name', null);
        $this->assertErrorCode($this->record($asset, 'return'), 409, 'INVALID_ASSET_STATUS');

        $this->assertErrorCode($this->record($asset, 'loan', ['borrower_name' => '']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->record($asset, 'loan', ['borrower_name' => 'Ani', 'due_date' => now()->subDays(3)->toDateString()]), 422, 'VALIDATION_FAILED');
    }

    public function test_one_transaction_covers_many_assets_all_or_nothing(): void
    {
        [$laptop, $printer, $truck] = [$this->asset('Laptop'), $this->asset('Printer'), $this->asset('Truk')];

        $rows = $this->recordMany([$laptop, $printer], 'transfer', ['to_location_id' => $this->otherLocation->id])
            ->assertCreated()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame($rows[0]['transaction_number'], $rows[1]['transaction_number']);
        $this->assertSame([$laptop, $printer], array_column($rows, 'asset_id'));
        foreach ([$laptop, $printer] as $asset) {
            $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.location.code', 'LOC2');
        }
        $this->assertSame(2, count($this->getJson("/api/transactions?search={$rows[0]['transaction_number']}")->json('data')));

        // One asset breaks a rule: nothing is recorded and the error names that asset.
        $this->record($printer, 'loan', ['borrower_name' => 'Budi'])->assertCreated();
        $number = $this->getJson("/api/assets/{$printer}")->json('data.asset_number');
        $response = $this->recordMany([$truck, $printer], 'loan', ['borrower_name' => 'Ani']);
        $this->assertErrorCode($response, 409, 'INVALID_ASSET_STATUS');
        $this->assertStringContainsString($number, $response->json('error.message'));
        $this->getJson("/api/assets/{$truck}")->assertJsonPath('data.status', 'available');
        $this->assertSame(1, count($this->getJson("/api/transactions?asset_id={$truck}")->json('data')));

        $this->assertErrorCode($this->recordMany([$truck, $truck], 'note', ['notes' => 'x']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->recordMany([], 'note', ['notes' => 'x']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->recordMany([$truck, (string) Str::uuid()], 'note', ['notes' => 'x']), 404, 'NOT_FOUND');
    }

    public function test_status_change_rules_and_permissions(): void
    {
        $asset = $this->asset();
        $this->assertErrorCode($this->record($asset, 'status_change', ['to_status' => 'available']), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->record($asset, 'status_change', ['to_status' => 'on_loan']), 422, 'VALIDATION_FAILED');

        // Operators may change status but not dispose assets.
        $operator = $this->createTenantUser($this->org, [$this->createRole($this->org, 'OPERATOR', self::OPERATOR)->code]);
        $this->actingAs($operator, 'web');
        $this->record($asset, 'status_change', ['to_status' => 'under_maintenance'])->assertCreated()
            ->assertJsonPath('data.from_status', 'available')->assertJsonPath('data.to_status', 'under_maintenance');
        $this->assertErrorCode($this->record($asset, 'status_change', ['to_status' => 'disposed']), 403, 'FORBIDDEN');

        $this->actingAs($this->createTenantUser($this->org, [$this->createRole($this->org, 'MANAGER', self::MANAGER)->code]), 'web');
        $this->record($asset, 'status_change', ['to_status' => 'disposed'])->assertCreated();
        $this->assertErrorCode($this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id]), 409, 'INVALID_ASSET_STATUS');

        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');
        $this->assertErrorCode($this->record($asset, 'note', ['notes' => 'cek']), 403, 'FORBIDDEN');
        $this->getJson('/api/transactions')->assertOk();
    }

    public function test_note_requires_text_and_does_not_change_the_asset(): void
    {
        $asset = $this->asset();
        $before = $this->getJson("/api/assets/{$asset}")->json('data');

        $this->assertErrorCode($this->record($asset, 'note'), 422, 'VALIDATION_FAILED');
        $this->record($asset, 'note', ['notes' => 'Inspeksi rutin'])->assertCreated()->assertJsonPath('data.type', 'note');
        $this->assertErrorCode($this->record($asset, 'registration'), 422, 'VALIDATION_FAILED');

        $this->assertSame($before['lock_version'], $this->getJson("/api/assets/{$asset}")->json('data.lock_version'));
    }

    public function test_transactions_are_append_only_at_database_level(): void
    {
        $id = $this->record($this->asset(), 'note', ['notes' => 'x'])->json('data.id');

        foreach ([fn () => DB::table('asset_transactions')->where('id', $id)->update(['notes' => 'ubah']),
            fn () => DB::table('asset_transactions')->where('id', $id)->delete()] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('History must not be editable.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_list_search_and_filters(): void
    {
        $laptop = $this->asset('Laptop Finance');
        $truck = $this->asset('Truk Box');
        $this->record($laptop, 'transfer', ['to_location_id' => $this->otherLocation->id, 'transaction_date' => now()->subDays(10)->toDateString()]);
        $this->record($truck, 'note', ['notes' => 'Servis berkala']);

        $count = fn (string $query) => count($this->getJson("/api/transactions?{$query}")->assertOk()->json('data'));
        $this->assertSame(4, $count(''));
        $this->assertSame(1, $count('type=transfer'));
        $this->assertSame(2, $count("asset_id={$truck}"));
        $this->assertSame(2, $count('search=finance'));
        $this->assertSame(1, $count('search=servis'));
        $this->assertSame(1, $count('type=transfer&date_to='.now()->subDays(5)->toDateString()));
        $this->assertSame(3, $count('date_from='.now()->subDays(5)->toDateString()));

        $trx = $this->getJson('/api/transactions?type=transfer')->json('data.0');
        $this->getJson("/api/transactions/{$trx['id']}")->assertOk()->assertJsonPath('data.asset.name', 'Laptop Finance');
    }

    public function test_data_scope_limits_assets_transactions_and_target_locations(): void
    {
        $inside = $this->asset('Di gudang');
        $outside = $this->asset('Di pusat', $this->otherLocation->id);
        $subLocation = $this->location('LOC1-A', 'Rak A', $this->masters['location']);

        $scoped = $this->createTenantUser($this->org, [$this->createRole($this->org, 'MANAGER', self::MANAGER)->code], [['scope_type' => 'location', 'ref_id' => $this->masters['location']->id]]);
        $this->actingAs($scoped, 'web');

        $this->assertSame([$inside], collect($this->getJson('/api/assets')->json('data'))->pluck('id')->all());
        $this->getJson("/api/assets/{$outside}")->assertNotFound();
        $this->assertErrorCode($this->record($outside, 'note', ['notes' => 'x']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->record($inside, 'transfer', ['to_location_id' => $this->otherLocation->id]), 422, 'VALIDATION_FAILED');
        $this->record($inside, 'transfer', ['to_location_id' => $subLocation->id])->assertCreated();
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'X', 'category_id' => $this->masters['category']->id, 'location_id' => $this->otherLocation->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'X', 'category_id' => $this->masters['category']->id]), 422, 'LOCATION_OUT_OF_SCOPE');

        $visible = collect($this->getJson('/api/transactions')->json('data'))->pluck('asset.id')->unique()->values()->all();
        $this->assertSame([$inside], $visible);
        $this->assertSame(1, $this->getJson('/api/dashboard')->json('data.summary.total'));
    }

    public function test_inspector_confirms_history_matches_state_and_detects_drift(): void
    {
        $asset = $this->asset();
        $this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id]);
        $this->record($asset, 'loan', ['borrower_name' => 'Budi']);
        $this->record($asset, 'return');
        $this->postJson("/api/assets/{$asset}/archive");
        $this->postJson("/api/assets/{$asset}/restore");

        $this->assertSame(0, Artisan::call('asset:inspect', ['asset' => $asset]));
        $this->assertStringContainsString('History is consistent', Artisan::output());
        $this->assertSame(0, Artisan::call('asset:inspect', ['--org' => 'ALPHA', '--verify-all' => true]));

        // A change made outside the transaction service shows up as drift.
        DB::table('assets')->where('id', $asset)->update(['status' => 'in_use']);
        $number = DB::table('assets')->where('id', $asset)->value('asset_number');
        $this->assertSame(1, Artisan::call('asset:inspect', ['asset' => $number, '--org' => 'ALPHA']));
        $this->assertStringContainsString('status is', Artisan::output());
    }

    public function test_disabled_module_answers_503_without_affecting_others(): void
    {
        $asset = $this->asset();
        config(['modules.disabled' => ['transactions']]);

        $this->assertErrorCode($this->getJson('/api/transactions'), 503, 'MODULE_UNAVAILABLE');
        $this->assertErrorCode($this->record($asset, 'note', ['notes' => 'x']), 503, 'MODULE_UNAVAILABLE');
        $this->getJson('/api/assets')->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
    }

    private function asset(string $name = 'Laptop', ?string $locationId = null): string
    {
        return $this->postJson('/api/assets', [
            'name' => $name,
            'category_id' => $this->masters['category']->id,
            'location_id' => $locationId ?? $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $data */
    private function record(string $assetId, string $type, array $data = [])
    {
        return $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => $type, ...$data]);
    }

    /**
     * @param  list<string>  $assetIds
     * @param  array<string, mixed>  $data
     */
    private function recordMany(array $assetIds, string $type, array $data = [])
    {
        return $this->postJson('/api/transactions', ['asset_ids' => $assetIds, 'type' => $type, ...$data]);
    }

    private function location(string $code, string $name, ?Location $parent = null): Location
    {
        $location = (new Location)->forceFill([
            'organization_id' => $this->org->id, 'code' => $code, 'name' => $name,
            'parent_id' => $parent?->id,
            'location_type_id' => $this->masters['location']->location_type_id,
        ]);
        $location->save();

        return $location;
    }
}
