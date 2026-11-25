<?php

namespace Tests\Feature\Deliveries;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Outgoing and incoming deliveries of cylinders: planning, scanning, the trip, and its effect on the assets. */
class DeliveryTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private TenantAssetCategory $cylinder;

    private TenantAssetCategory $vehicleCategory;

    private Asset $truck;

    private Employee $driver;

    private Employee $helper;

    private Customer $rs;

    private Customer $bengkel;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $categories = app(TenantCategoryService::class);
        $this->cylinder = $categories->installOne($this->org->id, StandardCategories::CYLINDER, StandardCategories::cylinder());
        $this->vehicleCategory = $categories->installOne($this->org->id, StandardCategories::VEHICLE, StandardCategories::all()[StandardCategories::VEHICLE]);
        $this->truck = $this->asset($this->vehicleCategory, 'Truk', 'RANGKA-1', ['nomor_polisi' => 'L 1234 AB']);
        $this->driver = $this->employee('Budi', 'driver');
        $this->helper = $this->employee('Andi', 'helper');
        $this->rs = $this->customer('RS Sehat');
        $this->bengkel = $this->customer('Bengkel Las');
        $this->actingAs($this->admin);
    }

    public function test_outgoing_delivery_to_two_customers_from_planning_to_completion(): void
    {
        [$a, $b, $c] = [$this->tabung('T-1'), $this->tabung('T-2'), $this->tabung('T-3')];
        $delivery = $this->plan('outgoing', [[$this->rs, [$a, $b]], [$this->bengkel, [$c]]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.counts.total', 3)
            ->json('data');
        $this->assertStringStartsWith('OUT-', $delivery['number']);
        [$stopRs, $stopBengkel] = array_column($delivery['stops'], 'id');
        $id = $delivery['id'];

        // Loading at the warehouse: by label (id) or typed No Manufaktur.
        $this->scan($id, 'load', $a->id)->assertOk()->assertJsonPath('data.status', 'loading');
        $this->scan($id, 'load', 't-2')->assertOk()->assertJsonPath('meta.already', false);
        $this->scan($id, 'load', 'T-2')->assertOk()->assertJsonPath('meta.already', true);

        // Not without a vehicle and a driver.
        $this->assertErrorCode($this->report($id, 'depart'), 422, 'CREW_REQUIRED');
        $this->crew($id)->assertOk()->assertJsonPath('data.driver.name', 'Budi')->assertJsonPath('data.helper.name', 'Andi')->assertJsonPath('data.vehicle.plate', 'L 1234 AB');
        $this->scan($id, 'load', 'T-3')->assertOk();
        $this->report($id, 'depart', ['latitude' => -7.25, 'longitude' => 112.75])->assertOk()->assertJsonPath('data.status', 'in_transit');

        // Leaving records the outgoing transaction of each cylinder, under the delivery's number.
        $this->assertSame('off_site', $a->fresh()->status);
        $rows = DB::table('asset_transactions')->where('transaction_number', $delivery['number'])->pluck('counterparty_name', 'asset_id');
        $this->assertSame('RS Sehat', $rows[$a->id]);
        $this->assertSame('Bengkel Las', $rows[$c->id]);
        $this->getJson("/api/assets/{$a->id}")->assertJsonPath('data.position.state', 'in_transit')->assertJsonPath('data.position.label', 'Dikirim ke RS Sehat');

        // The way there.
        $this->report($id, 'position', ['latitude' => -7.3, 'longitude' => 112.7])->assertOk();
        $this->assertErrorCode($this->report($id, 'position'), 422, 'VALIDATION_FAILED');
        $this->report($id, 'issue', ['note' => 'Ban bocor'])->assertOk()->assertJsonPath('data.last_event.type', 'issue');
        $this->getJson('/api/deliveries/tracking')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.last_event.note', 'Ban bocor')
            ->assertJsonPath('data.0.last_position.latitude', -7.3);

        $this->report($id, 'arrive', ['stop_id' => $stopRs])->assertOk();
        $this->assertErrorCode($this->scan($id, 'unload', 'T-3', $stopRs), 409, 'OTHER_CUSTOMER');
        $this->scan($id, 'unload', 'T-1', $stopRs)->assertOk();
        // One cylinder is refused: needs a reason.
        $this->assertErrorCode($this->report($id, 'stop_done', ['stop_id' => $stopRs]), 422, 'VALIDATION_FAILED');
        $this->report($id, 'stop_done', ['stop_id' => $stopRs, 'note' => 'Customer menolak 1', 'receiver_name' => 'Ibu Sri'])->assertOk()
            ->assertJsonPath('data.stops.0.status', 'done')->assertJsonPath('data.stops.0.receiver_name', 'Ibu Sri');
        $this->getJson("/api/assets/{$a->id}")->assertJsonPath('data.position.label', 'Di RS Sehat');

        $this->assertErrorCode($this->report($id, 'completed'), 409, 'STOP_OPEN');
        $this->scan($id, 'unload', 'T-3', $stopBengkel)->assertOk()->assertJsonPath('data.stops.1.status', 'arrived');
        $this->report($id, 'stop_done', ['stop_id' => $stopBengkel])->assertOk();
        $this->assertErrorCode($this->report($id, 'completed'), 422, 'VALIDATION_FAILED');
        $this->report($id, 'completed', ['note' => 'Dibawa kembali'])->assertOk()->assertJsonPath('data.status', 'completed');

        // The refused cylinder is back, the delivered ones are at their customers.
        $this->assertSame('available', $b->fresh()->status);
        $this->assertSame('off_site', $c->fresh()->status);
        $this->assertSame(0, DB::table('delivery_items')->where('active', true)->count());
        $this->assertSame(0, $this->artisan('asset:inspect', ['--org' => 'ALPHA', '--verify-all' => true])->run());
        $this->assertErrorCode($this->report($id, 'position', ['latitude' => 1, 'longitude' => 1]), 409, 'DELIVERY_ENDED');
    }

    public function test_incoming_delivery_picks_up_at_the_customer_and_records_what_reached_the_warehouse(): void
    {
        [$a, $b] = [$this->tabung('T-1'), $this->tabung('T-2')];
        $extra = $this->tabung('T-3');
        $this->deliver([$a, $b, $extra], $this->rs);
        $this->getJson('/api/deliveries/cylinders?direction=incoming&customer_id='.$this->rs->id)->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/deliveries/cylinders?direction=incoming&customer_id='.$this->bengkel->id)->assertOk()->assertJsonPath('meta.total', 0);

        $delivery = $this->plan('incoming', [[$this->rs, [$a, $b]]])->assertCreated()->json('data');
        $this->assertStringStartsWith('IN-', $delivery['number']);
        $id = $delivery['id'];
        $this->assertErrorCode($this->scan($id, 'load', 'T-1'), 409, 'DELIVERY_NOT_STARTED');
        $this->crew($id)->assertOk();
        $this->report($id, 'depart')->assertOk();

        // Loading at the customer marks the arrival; a cylinder that was not planned may join.
        $this->scan($id, 'load', 'T-1')->assertOk()->assertJsonPath('data.stops.0.status', 'arrived');
        $this->scan($id, 'load', 'T-3')->assertOk()->assertJsonPath('data.counts.total', 3);
        $this->assertErrorCode($this->scan($id, 'unload', 'T-1'), 409, 'STOP_OPEN');
        $this->report($id, 'stop_done')->assertOk();
        $this->assertErrorCode($this->scan($id, 'load', 'T-2'), 409, 'ALREADY_UNLOADED');

        $this->report($id, 'return')->assertOk();
        $this->scan($id, 'unload', 'T-1')->assertOk();
        $this->assertErrorCode($this->scan($id, 'unload', 'T-2'), 422, 'NOT_LOADED');
        // T-3 was loaded but is not unloaded.
        $this->assertErrorCode($this->report($id, 'completed'), 422, 'VALIDATION_FAILED');
        $this->report($id, 'completed', ['note' => 'Dicari'])->assertOk();

        $this->assertSame('available', $a->fresh()->status);
        $this->assertSame('off_site', $b->fresh()->status);
        $this->assertSame('off_site', $extra->fresh()->status);
        $this->assertSame('incoming', DB::table('asset_transactions')->where('transaction_number', $delivery['number'])->value('type'));
        $this->getJson("/api/assets/{$extra->id}")->assertJsonPath('data.position.state', 'missing');
        $this->getJson("/api/assets/{$a->id}")->assertJsonPath('data.position.state', 'warehouse');
        $this->assertSame(0, $this->artisan('asset:inspect', ['--org' => 'ALPHA', '--verify-all' => true])->run());
    }

    public function test_a_cylinder_on_an_open_delivery_takes_no_other_transaction(): void
    {
        $a = $this->tabung('T-1');
        $id = $this->plan('outgoing', [[$this->rs, [$a]]])->assertCreated()->json('data.id');

        $this->assertErrorCode($this->plan('outgoing', [[$this->bengkel, [$a]]]), 409, 'ASSET_LOCKED');
        $this->getJson('/api/deliveries/cylinders?direction=outgoing')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/deliveries/cylinders?direction=outgoing&delivery_id={$id}")->assertJsonPath('meta.total', 1);
        $this->assertErrorCode($this->postJson("/api/assets/{$a->id}/archive"), 409, 'ASSET_LOCKED');
        $this->assertErrorCode($this->postJson('/api/transactions', ['type' => 'status_change', 'asset_ids' => [$a->id], 'to_status' => 'under_maintenance']), 409, 'ASSET_LOCKED');

        $this->postJson("/api/deliveries/{$id}/cancel", ['reason' => 'Batal kirim'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->plan('outgoing', [[$this->bengkel, [$a]]])->assertCreated();
    }

    public function test_only_cylinders_that_can_move_are_accepted(): void
    {
        $broken = $this->tabung('T-1');
        DB::table('assets')->where('id', $broken->id)->update(['status' => 'under_maintenance']);
        $this->assertErrorCode($this->plan('outgoing', [[$this->rs, [$broken]]]), 422, 'VALIDATION_FAILED');
        // A vehicle is not a cylinder.
        $this->assertErrorCode($this->plan('outgoing', [[$this->rs, [$this->truck]]]), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->plan('outgoing', [[$this->rs, []]]), 422, 'VALIDATION_FAILED');
        $atWarehouse = $this->tabung('T-2');
        $this->assertErrorCode($this->plan('incoming', [[$this->rs, [$atWarehouse]]]), 422, 'VALIDATION_FAILED');

        $id = $this->plan('outgoing', [[$this->rs, [$atWarehouse]]])->json('data.id');
        $this->assertErrorCode($this->scan($id, 'load', $this->truck->id), 404, 'CYLINDER_NOT_FOUND');
        $this->assertErrorCode($this->scan($id, 'load', 'T-1'), 409, 'INVALID_ASSET_STATUS');
    }

    public function test_plan_can_be_changed_until_loading_and_a_load_scan_taken_back(): void
    {
        [$a, $b] = [$this->tabung('T-1'), $this->tabung('T-2')];
        $id = $this->plan('outgoing', [[$this->rs, [$a]]])->json('data.id');
        $body = $this->body([[$this->bengkel, [$a, $b]]]);
        $this->putJson("/api/deliveries/{$id}", $body)->assertOk()->assertJsonPath('data.counts.total', 2)->assertJsonPath('data.stops.0.customer.name', 'Bengkel Las');

        $this->scan($id, 'load', 'T-1')->assertOk();
        $this->assertErrorCode($this->putJson("/api/deliveries/{$id}", $body), 409, 'DELIVERY_STARTED');
        $this->postJson("/api/deliveries/{$id}/unscan", ['asset_id' => $a->id])->assertOk()->assertJsonPath('data.counts.loaded', 0);

        // Cylinders left behind are free again once the vehicle leaves.
        $this->crew($id);
        $this->scan($id, 'load', 'T-2')->assertOk();
        $this->report($id, 'depart')->assertOk();
        $this->assertSame('available', $a->fresh()->status);
        $this->plan('outgoing', [[$this->rs, [$a]]])->assertCreated();
        $this->assertErrorCode($this->postJson("/api/deliveries/{$id}/cancel", ['reason' => 'Batal kirim']), 409, 'DELIVERY_ON_THE_WAY');
    }

    public function test_a_vehicle_and_its_crew_are_on_one_trip_at_a_time(): void
    {
        $first = $this->plan('outgoing', [[$this->rs, [$this->tabung('T-1')]]])->json('data.id');
        $second = $this->plan('outgoing', [[$this->rs, [$this->tabung('T-2')]]])->json('data.id');
        $this->scan($first, 'load', 'T-1');
        $this->scan($second, 'load', 'T-2');
        $this->crew($first)->assertOk();
        $this->crew($second)->assertOk();
        $this->report($first, 'depart')->assertOk();
        $this->assertErrorCode($this->report($second, 'depart'), 409, 'CREW_BUSY');
        $this->assertErrorCode($this->patchJson("/api/employees/{$this->driver->id}", ['status' => 'inactive']), 409, 'EMPLOYEE_IN_USE');
        $this->assertErrorCode($this->postJson("/api/customers/{$this->rs->id}/archive"), 409, 'CUSTOMER_IN_USE');

        $this->assertErrorCode($this->putJson("/api/deliveries/{$second}/crew", ['driver_id' => $this->helper->id]), 422, 'VALIDATION_FAILED');
    }

    public function test_a_report_sent_twice_is_recorded_once_and_implausible_times_are_replaced(): void
    {
        $id = $this->plan('outgoing', [[$this->rs, [$this->tabung('T-1')]]])->json('data.id');
        $this->scan($id, 'load', 'T-1');
        $this->crew($id);
        $client = (string) Str::uuid();
        $this->report($id, 'depart', ['client_id' => $client])->assertOk();
        $this->report($id, 'depart', ['client_id' => $client])->assertOk();
        $this->assertSame(1, DB::table('delivery_events')->where('type', 'depart')->count());

        $this->report($id, 'position', ['latitude' => -7, 'longitude' => 112, 'recorded_at' => now()->addYear()->toIso8601String()])->assertOk();
        $this->report($id, 'position', ['latitude' => -7, 'longitude' => 112, 'recorded_at' => now()->subHour()->toIso8601String()])->assertOk();
        $times = DB::table('delivery_events')->where('type', 'position')->orderBy('recorded_at')->pluck('recorded_at');
        $this->assertTrue(now()->subMinutes(50)->gt($times[0]));
        $this->assertTrue(now()->addMinute()->gt($times[1]));
    }

    public function test_permissions_separate_planning_from_operating(): void
    {
        $this->createRole($this->org, 'SUPIR', ['delivery.view', 'delivery.operate']);
        $driverUser = $this->createTenantUser($this->org, ['SUPIR']);
        $outsider = $this->createTenantUser($this->org, [$this->viewerRole($this->org)]);
        $a = $this->tabung('T-1');
        $id = $this->plan('outgoing', [[$this->rs, [$a]]])->json('data.id');

        $this->actingAs($driverUser);
        $this->getJson('/api/deliveries')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/deliveries/options')->assertOk()->assertJsonPath('data.drivers.0.name', 'Budi')->assertJsonPath('data.vehicles.0.plate', 'L 1234 AB');
        $this->scan($id, 'load', 'T-1')->assertOk();
        $this->crew($id)->assertOk();
        $this->report($id, 'depart')->assertOk();
        $this->plan('outgoing', [[$this->rs, [$a]]])->assertForbidden();
        $this->postJson("/api/deliveries/{$id}/cancel", ['reason' => 'Batal kirim'])->assertForbidden();

        $this->actingAs($outsider);
        $this->getJson('/api/deliveries')->assertForbidden();
        $this->scan($id, 'unload', 'T-1')->assertForbidden();
    }

    public function test_another_organization_neither_sees_nor_uses_the_records(): void
    {
        $id = $this->plan('outgoing', [[$this->rs, [$this->tabung('T-1')]]])->json('data.id');
        ['organization' => $beta, 'admin' => $betaAdmin] = $this->createOrganization('BETA');
        $this->actingAs($betaAdmin);

        $this->getJson("/api/deliveries/{$id}")->assertNotFound();
        $this->getJson('/api/deliveries/tracking')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(0, 'data');
        $this->assertErrorCode($this->plan('incoming', [[$this->rs, []]]), 422, 'VALIDATION_FAILED');
    }

    public function test_mobile_token_may_operate_but_not_plan(): void
    {
        $id = $this->plan('outgoing', [[$this->rs, [$this->tabung('T-1')]]])->json('data.id');
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/auth/mobile/login', ['email' => $this->admin->email, 'password' => self::PASSWORD, 'device_name' => 'HP'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson("/api/deliveries/{$id}/scan", ['phase' => 'load', 'code' => 'T-1'], $headers)->assertOk();
        $this->assertErrorCode($this->postJson("/api/deliveries/{$id}/cancel", ['reason' => 'Batal kirim'], $headers), 403, 'MOBILE_TOKEN_READ_ONLY');
    }

    public function test_employees_and_customers_are_master_data(): void
    {
        $this->postJson('/api/employees', ['name' => 'Cahyo'])->assertUnprocessable();
        $employee = $this->postJson('/api/employees', ['name' => 'Cahyo', 'position' => 'driver', 'license_expires_on' => '2027-01-31'])
            ->assertCreated()->assertJsonPath('data.code', 'CAHYO')->assertJsonPath('data.position_label', 'Supir')->json('data.id');
        $this->getJson('/api/employees?position=helper')->assertJsonCount(1, 'data');
        $this->postJson("/api/employees/{$employee}/archive")->assertOk();
        $this->postJson("/api/employees/{$employee}/restore")->assertOk()->assertJsonPath('data.status', 'active');

        // Without approval.manage the change waits for approval, like other master data.
        $this->actingAs($this->createTenantUser($this->org, ['ADMIN']));
        $this->postJson('/api/customers', ['name' => 'Klinik Baru', 'phone' => '0812'])->assertStatus(202);
        $this->assertFalse(Customer::query()->where('name', 'Klinik Baru')->exists());
    }

    public function test_without_licensing_and_locations_assets_need_neither(): void
    {
        config(['edition.licensing' => false, 'edition.locations' => false]);
        OrganizationLicense::query()->where('organization_id', $this->org->id)->get()->each(fn (OrganizationLicense $license) => app(Licensing::class)->revoke($license, 'Uji tanpa lisensi'));
        $asset = $this->postJson('/api/assets', ['name' => 'Oksigen', 'serial_number' => 'T-9', 'category_id' => $this->cylinder->id, 'attributes' => $this->values()])
            ->assertCreated()->assertJsonPath('data.location_id', null)->json('data');
        $this->assertStringStartsWith('AST-', $asset['reference_number']);

        config(['edition.licensing' => true]);
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'Oksigen']), 403, 'LICENSE_INACTIVE');
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<array{0: Customer, 1: list<Asset>}> $stops */
    private function body(array $stops): array
    {
        return [
            'planned_date' => now()->toDateString(),
            'stops' => array_map(fn (array $s) => ['customer_id' => $s[0]->id, 'asset_ids' => array_map(fn (Asset $a) => $a->id, $s[1])], $stops),
        ];
    }

    /** @param list<array{0: Customer, 1: list<Asset>}> $stops */
    private function plan(string $direction, array $stops): TestResponse
    {
        return $this->postJson('/api/deliveries', ['direction' => $direction, ...$this->body($stops)]);
    }

    private function scan(string $id, string $phase, string $code, ?string $stop = null): TestResponse
    {
        return $this->postJson("/api/deliveries/{$id}/scan", ['phase' => $phase, 'code' => $code, 'stop_id' => $stop]);
    }

    private function report(string $id, string $type, array $data = []): TestResponse
    {
        return $this->postJson("/api/deliveries/{$id}/events", ['type' => $type, ...$data]);
    }

    private function crew(string $id): TestResponse
    {
        return $this->putJson("/api/deliveries/{$id}/crew", ['vehicle_asset_id' => $this->truck->id, 'driver_id' => $this->driver->id, 'helper_id' => $this->helper->id]);
    }

    /** @param list<Asset> $cylinders */
    private function deliver(array $cylinders, Customer $customer): void
    {
        $id = $this->plan('outgoing', [[$customer, $cylinders]])->assertCreated()->json('data.id');
        $this->crew($id)->assertOk();
        foreach ($cylinders as $cylinder) {
            $this->scan($id, 'load', $cylinder->serial_number)->assertOk();
        }
        $this->report($id, 'depart')->assertOk();
        foreach ($cylinders as $cylinder) {
            $this->scan($id, 'unload', $cylinder->serial_number)->assertOk();
        }
        $this->report($id, 'stop_done')->assertOk();
        $this->report($id, 'completed')->assertOk();
    }

    private function tabung(string $serial): Asset
    {
        return $this->asset($this->cylinder, 'Oksigen Medis', $serial, $this->values());
    }

    private function asset(TenantAssetCategory $category, string $name, string $serial, array $values): Asset
    {
        $asset = (new Asset)->forceFill(['organization_id' => $this->org->id, 'name' => $name, 'serial_number' => $serial,
            'category_id' => $category->id, 'status' => 'available', 'attribute_values' => $values]);
        $asset->save();
        $row = (new AssetTransaction)->forceFill(['organization_id' => $this->org->id, 'asset_id' => $asset->id,
            'transaction_number' => 'REG-'.$serial, 'type' => 'registration', 'transaction_date' => now()->toDateString(), 'to_status' => 'available']);
        $row->save();

        return $asset;
    }

    /** The keys of this category's fields, by label (a key taken by another category gets a suffix). */
    private function values(): array
    {
        $values = ['Business Unit' => 'PT_SIG', 'Warna' => 'Putih', 'Bahan' => 'Baja', 'Volume Tabung' => 40, 'Satuan Volume' => 'LITER',
            'Jenis Gas' => 'Oksigen', 'Grade Gas' => 'Medis', 'Jenis Tabung' => 'Besar', 'UOM' => 'TBG', 'Satuan Ukuran' => 'TBG'];
        $keys = TenantCategoryAttribute::query()->where('category_id', $this->cylinder->id)->pluck('key', 'label');

        return collect($values)->mapWithKeys(fn ($value, $label) => [$keys[$label] => $value])->all();
    }

    private function employee(string $name, string $position): Employee
    {
        $employee = (new Employee)->forceFill(['organization_id' => $this->org->id, 'code' => strtoupper($name), 'name' => $name, 'position' => $position]);
        $employee->save();

        return $employee;
    }

    private function customer(string $name): Customer
    {
        $customer = (new Customer)->forceFill(['organization_id' => $this->org->id, 'code' => strtoupper(Str::slug($name)), 'name' => $name]);
        $customer->save();

        return $customer;
    }
}
