<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Outgoing and incoming transactions (docs/factory/features/F-02, APR-0006): assets leaving the
 * organization temporarily (off_site) or permanently (disposed), and coming back.
 */
class OutgoingIncomingTransactionTest extends TestCase
{
    /** approval.manage: changes apply directly (approval is covered by ApprovalTest). */
    private const BASE = ['organization.view', 'master.tenant.view', 'asset.view', 'asset.create', 'transaction.view', 'transaction.create', 'approval.manage'];

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
        $this->otherLocation = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC2', 'name' => 'Kantor Pusat']);
        $this->otherLocation->save();
        $this->actingAs($this->admin, 'web');
    }

    public function test_temporary_outgoing_then_incoming_cycle(): void
    {
        $asset = $this->asset();

        $out = $this->record($asset, 'outgoing', $this->temporary(['counterparty_org' => 'PT Mitra', 'counterparty_contact' => '0812', 'document_number' => 'SJ-001']))
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Transaksi keluar')
            ->assertJsonPath('data.from_status', 'available')
            ->assertJsonPath('data.to_status', 'off_site')
            ->assertJsonPath('data.outgoing_nature', 'temporary')
            ->assertJsonPath('data.outgoing_nature_label', 'Sementara')
            ->assertJsonPath('data.reason_label', 'Perbaikan di vendor')
            ->assertJsonPath('data.counterparty_name', 'CV Servis Jaya')
            ->assertJsonPath('data.document_number', 'SJ-001')
            ->json('data');
        $this->assertStringStartsWith('OUT-', $out['transaction_number']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'off_site');

        $in = $this->record($asset, 'incoming', ['to_status' => 'under_maintenance', 'to_location_id' => $this->otherLocation->id, 'document_number' => 'BAST-9'])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Transaksi masuk')
            ->assertJsonPath('data.from_status', 'off_site')
            ->assertJsonPath('data.to_status', 'under_maintenance')
            ->assertJsonPath('data.outgoing_transaction_id', $out['id'])
            ->assertJsonPath('data.counterparty_name', 'CV Servis Jaya')   // defaults to the outgoing party
            ->assertJsonPath('data.to_location.code', 'LOC2')
            ->json('data');
        $this->assertStringStartsWith('IN-', $in['transaction_number']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance')->assertJsonPath('data.location.code', 'LOC2');

        $this->assertSame(0, Artisan::call('asset:inspect', ['asset' => $asset]));
        $this->assertErrorCode($this->record($asset, 'incoming'), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_off_site_asset_accepts_no_other_change_until_it_comes_back(): void
    {
        $asset = $this->asset();
        $this->record($asset, 'outgoing', $this->temporary())->assertCreated();

        $this->assertErrorCode($this->record($asset, 'transfer', ['to_location_id' => $this->otherLocation->id]), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->record($asset, 'status_change', ['to_status' => 'in_use']), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->record($asset, 'loan', ['borrower_name' => 'Budi']), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->record($asset, 'outgoing', $this->temporary()), 409, 'INVALID_ASSET_STATUS');
        $this->assertErrorCode($this->postJson("/api/assets/{$asset}/archive"), 409, 'ASSET_OFF_SITE');
        $this->record($asset, 'note', ['notes' => 'Dicek via telepon'])->assertCreated();
    }

    public function test_permanent_outgoing_disposes_the_asset_and_needs_dispose_permission(): void
    {
        $asset = $this->asset();
        $permanent = ['outgoing_nature' => 'permanent', 'reason_code' => 'sold', 'counterparty_name' => 'Pembeli'];

        $this->actingAs($this->member(['asset.outgoing']), 'web');
        $this->assertErrorCode($this->record($asset, 'outgoing', $permanent), 403, 'FORBIDDEN');

        $this->actingAs($this->admin, 'web');
        $this->record($asset, 'outgoing', [...$permanent, 'due_date' => now()->addWeek()->toDateString()])->assertCreated()
            ->assertJsonPath('data.to_status', 'disposed')
            ->assertJsonPath('data.reason_label', 'Dijual')
            ->assertJsonPath('data.due_date', null);   // a permanent outgoing has no return date
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'disposed');
        $this->assertErrorCode($this->record($asset, 'incoming'), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_outgoing_and_incoming_validation(): void
    {
        $asset = $this->asset();
        $invalid = [
            'no nature' => ['reason_code' => 'sold', 'counterparty_name' => 'X'],
            'reason of the other nature' => $this->temporary(['reason_code' => 'sold']),
            'unknown reason' => $this->temporary(['reason_code' => 'stolen']),
            'other without notes' => $this->temporary(['reason_code' => 'other']),
            'no counterparty' => $this->temporary(['counterparty_name' => '  ']),
            'temporary without return date' => $this->temporary(['due_date' => null]),
            'return before leaving' => $this->temporary(['transaction_date' => now()->toDateString(), 'due_date' => now()->subDay()->toDateString()]),
            'future date' => $this->temporary(['transaction_date' => now()->addDays(2)->toDateString()]),
        ];
        foreach ($invalid as $case => $data) {
            $this->assertSame(422, $this->record($asset, 'outgoing', $data)->status(), $case);
        }
        $this->record($asset, 'outgoing', $this->temporary(['reason_code' => 'other', 'notes' => 'Pameran']))->assertCreated();

        $leftOn = DB::table('asset_transactions')->where('asset_id', $asset)->where('type', 'outgoing')->value('transaction_date');
        $this->assertErrorCode($this->record($asset, 'incoming', ['transaction_date' => now()->parse($leftOn)->subDay()->toDateString()]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->record($asset, 'incoming', ['to_status' => 'in_use']), 422, 'VALIDATION_FAILED');

        $notOut = $this->asset('Printer');
        $this->assertErrorCode($this->record($notOut, 'incoming'), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_outgoing_requires_an_active_or_inactive_asset(): void
    {
        $asset = $this->asset();
        $this->record($asset, 'loan', ['borrower_name' => 'Budi'])->assertCreated();

        $this->assertErrorCode($this->record($asset, 'outgoing', $this->temporary()), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_type_permissions(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->member([]), 'web');
        $this->assertErrorCode($this->record($asset, 'outgoing', $this->temporary()), 403, 'FORBIDDEN');

        $this->actingAs($this->member(['asset.outgoing']), 'web');
        $this->record($asset, 'outgoing', $this->temporary())->assertCreated();
        $this->assertErrorCode($this->record($asset, 'incoming'), 403, 'FORBIDDEN');

        $this->actingAs($this->member(['asset.incoming']), 'web');
        $this->record($asset, 'incoming')->assertCreated()->assertJsonPath('data.to_status', 'available');
    }

    public function test_one_outgoing_transaction_covers_many_assets_all_or_nothing(): void
    {
        [$a, $b, $c] = [$this->asset('A'), $this->asset('B'), $this->asset('C')];

        $rows = $this->postJson('/api/transactions', ['asset_ids' => [$a, $b], 'type' => 'outgoing', ...$this->temporary()])->assertCreated()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['transaction_number'], $rows[1]['transaction_number']);

        $this->record($c, 'loan', ['borrower_name' => 'Budi'])->assertCreated();
        $this->assertErrorCode($this->postJson('/api/transactions', ['asset_ids' => [$a, $c], 'type' => 'incoming']), 409, 'INVALID_ASSET_STATUS');
        $this->getJson("/api/assets/{$a}")->assertJsonPath('data.status', 'off_site');

        // Each incoming row closes the outgoing row of its own asset.
        $back = $this->postJson('/api/transactions', ['asset_ids' => [$a, $b], 'type' => 'incoming'])->assertCreated()->json('data');
        $byAsset = collect($rows)->keyBy('asset_id');
        foreach ($back as $row) {
            $this->assertSame($byAsset[$row['asset_id']]['id'], $row['outgoing_transaction_id']);
        }
    }

    public function test_search_filter_and_utilization(): void
    {
        $asset = $this->asset();
        $this->asset('Lainnya');
        $this->record($asset, 'outgoing', $this->temporary(['document_number' => 'SJ-777']))->assertCreated();

        $this->assertSame([$asset], collect($this->getJson('/api/transactions?type=outgoing')->json('data'))->pluck('asset.id')->all());
        $this->assertCount(1, $this->getJson('/api/transactions?search=Servis')->json('data'));
        $this->assertCount(1, $this->getJson('/api/transactions?search=SJ-777')->json('data'));

        $this->assertSame(1, $this->widgetData($this->org, $this->admin, 'assets_off_site')['value']);
        $this->assertSame(1, $this->widgetData($this->org, $this->admin, 'active_assets')['value']);   // off_site counts as in service, like on_loan
        $this->assertSame(2, $this->widgetData($this->org, $this->admin, 'total_assets')['value']);
    }

    public function test_list_tabs_counts_and_return_status(): void
    {
        $back = $this->asset('Kembali');
        $out = $this->asset('Di luar');
        $late = $this->asset('Terlambat');
        $this->record($back, 'outgoing', $this->temporary(['transaction_date' => now()->subDays(5)->toDateString()]))->assertCreated();
        $this->record($back, 'incoming')->assertCreated();
        $this->record($out, 'outgoing', $this->temporary())->assertCreated();
        $this->record($late, 'outgoing', $this->temporary([
            'transaction_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
        ]))->assertCreated();

        $notReturned = collect($this->getJson('/api/transactions?not_returned=1')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$out, $late], $notReturned->pluck('asset.id')->all());
        $this->assertSame('Terlambat', $notReturned->firstWhere('asset.id', $late)['return_status']);
        $this->assertSame(10, $notReturned->firstWhere('asset.id', $late)['days_out']);
        $this->assertSame('Belum kembali', $notReturned->firstWhere('asset.id', $out)['return_status']);

        $backRow = collect($this->getJson('/api/transactions?type=outgoing')->json('data'))->firstWhere('asset.id', $back);
        $this->assertSame('Sudah kembali', $backRow['return_status']);
        $this->assertSame(5, $backRow['days_out']);
        $this->assertCount(3, $this->getJson('/api/transactions?type=outgoing&outgoing_nature=temporary')->json('data'));

        // Registrations (3) + outgoing (3) + incoming (1); the tab filter itself is ignored.
        $this->getJson('/api/transactions/counts?type=incoming')->assertOk()->assertExactJson(['data' => [
            'all' => 7, 'outgoing' => 3, 'incoming' => 1, 'not_returned' => 2,
        ]]);
        $this->getJson('/api/transactions/counts?search=Terlambat')->assertJsonPath('data.all', 2)->assertJsonPath('data.not_returned', 1);
        $this->assertErrorCode($this->getJson('/api/transactions/counts?type=bogus'), 422, 'VALIDATION_FAILED');
    }

    public function test_dashboard_shows_assets_outside_and_late_returns(): void
    {
        $late = $this->asset('Proyektor');
        $soon = $this->asset('Kamera');
        $back = $this->asset('Laptop');
        $this->record($late, 'outgoing', $this->temporary([
            'transaction_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDays(3)->toDateString(), 'counterparty_name' => 'Dinas Pendidikan',
        ]))->assertCreated();
        $this->record($soon, 'outgoing', $this->temporary(['due_date' => now()->addDays(2)->toDateString()]))->assertCreated();
        $this->record($back, 'outgoing', $this->temporary([
            'transaction_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDays(5)->toDateString(),
        ]))->assertCreated();
        $this->record($back, 'incoming')->assertCreated();

        $widget = fn (string $key) => $this->widgetData($this->org, $this->admin, $key);
        $this->assertSame(2, $widget('assets_off_site')['value']);
        $this->assertSame(1, $widget('due_soon_list')['count']);
        $this->assertSame([3, 1], $widget('outgoing_vs_incoming')['series'][0]['data']); // keluar, masuk

        $overdue = $widget('overdue_outgoing_list');
        $this->assertSame(1, $overdue['count']);
        $this->assertSame($late, $overdue['rows'][0]['asset']['id']);
        $this->assertSame('Dinas Pendidikan', $overdue['rows'][0]['counterparty']);
        $this->assertSame(now()->subDays(3)->toDateString(), $overdue['rows'][0]['due_date']);
        $this->assertSame(3, $overdue['rows'][0]['days_late']);
    }

    public function test_counterparty_contact_needs_contact_permission(): void
    {
        $asset = $this->asset();
        $this->record($asset, 'outgoing', $this->temporary(['counterparty_contact' => '0812-555']))->assertCreated();
        $this->getJson('/api/transactions?type=outgoing')->assertJsonPath('data.0.counterparty_contact', '0812-555');

        $this->actingAs($this->member([]), 'web');
        $this->getJson('/api/transactions?type=outgoing')->assertOk()
            ->assertJsonPath('data.0.counterparty_name', 'CV Servis Jaya')
            ->assertJsonPath('data.0.counterparty_contact', null);

        $this->actingAs($this->member(['transaction.contact.view']), 'web');
        $this->getJson('/api/transactions?type=outgoing')->assertJsonPath('data.0.counterparty_contact', '0812-555');
    }

    public function test_database_rejects_outgoing_rows_without_their_required_fields(): void
    {
        $constraints = collect(DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'asset_transactions'::regclass"))->pluck('conname');
        foreach (['asset_transactions_outgoing_check', 'asset_transactions_temporary_due_check', 'asset_transactions_incoming_check',
            'asset_transactions_outgoing_nature_check', 'asset_transactions_outgoing_transaction_id_tfk'] as $name) {
            $this->assertContains($name, $constraints, $name);
        }
        $check = collect(DB::select("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'assets_status_check'"))->value('def');
        $this->assertStringContainsString('off_site', $check);
    }

    private function asset(string $name = 'Laptop'): string
    {
        return $this->postJson('/api/assets', [
            'name' => $name,
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            'status' => 'available',
        ])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $data */
    private function record(string $assetId, string $type, array $data = [])
    {
        return $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => $type, ...$data]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function temporary(array $overrides = []): array
    {
        return [
            'outgoing_nature' => 'temporary',
            'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis Jaya',
            'due_date' => now()->addWeek()->toDateString(),
            ...$overrides,
        ];
    }

    /** @param list<string> $extra */
    private function member(array $extra): User
    {
        static $n = 0;
        $role = $this->createRole($this->org, 'OUT_ROLE_'.(++$n), [...self::BASE, ...$extra])->code;

        return $this->createTenantUser($this->org, [$role]);
    }
}
