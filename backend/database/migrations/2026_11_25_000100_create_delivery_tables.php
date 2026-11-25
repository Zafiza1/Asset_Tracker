<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cylinder deliveries (edition PT SIG / PT CDO): outgoing (warehouse to customers) and incoming
 * (customers back to the warehouse), with everything that happens between loading and unloading.
 *
 *  - employees, customers: master data; drivers and helpers are employees.
 *  - deliveries: one trip of a vehicle with a driver and a helper.
 *  - delivery_stops: its customers, in order.
 *  - delivery_items: its cylinders. An `active` row holds the cylinder: a partial unique index
 *    allows one open delivery per cylinder, also under concurrency.
 *  - delivery_events: what happened on the way, with the phone's position (append-only).
 */
return new class extends Migration
{
    private const CODES = ['delivery.view', 'delivery.manage', 'delivery.operate'];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('position', 20);
            $table->string('phone', 30)->nullable();
            $table->string('license_number', 50)->nullable();
            $table->date('license_expires_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('employees');
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_position_check CHECK (position IN ('driver','helper','warehouse','admin','other'))");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active','inactive','archived'))");

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 10)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('customers');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active','inactive','archived'))");

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('number', 60);
            $table->string('direction', 10);
            $table->string('status', 12)->default('draft');
            $table->uuid('vehicle_asset_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->uuid('helper_id')->nullable();
            $table->date('planned_date');
            $table->date('expected_return_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('departed_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->text('end_note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });
        TenantSchema::tenantKey('deliveries');
        TenantSchema::tenantForeign('deliveries', 'vehicle_asset_id', 'assets');
        TenantSchema::tenantForeign('deliveries', 'driver_id', 'employees');
        TenantSchema::tenantForeign('deliveries', 'helper_id', 'employees');
        TenantSchema::memberForeign('deliveries', 'created_by');
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_direction_check CHECK (direction IN ('outgoing','incoming'))");
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check CHECK (status IN ('draft','loading','in_transit','completed','cancelled'))");
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_end_check CHECK ((status IN ('completed','cancelled')) = (ended_at IS NOT NULL))");
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_crew_check CHECK (driver_id IS NULL OR helper_id IS NULL OR driver_id <> helper_id)');
        // Nothing leaves without a vehicle and a driver.
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_departure_check CHECK (status NOT IN ('in_transit','completed') OR (departed_at IS NOT NULL AND vehicle_asset_id IS NOT NULL AND driver_id IS NOT NULL))");
        // A vehicle, a driver and a helper are on one trip at a time.
        DB::statement("CREATE UNIQUE INDEX deliveries_vehicle_on_trip ON deliveries (organization_id, vehicle_asset_id) WHERE status = 'in_transit'");
        DB::statement("CREATE UNIQUE INDEX deliveries_driver_on_trip ON deliveries (organization_id, driver_id) WHERE status = 'in_transit'");
        DB::statement("CREATE UNIQUE INDEX deliveries_helper_on_trip ON deliveries (organization_id, helper_id) WHERE status = 'in_transit'");

        Schema::create('delivery_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('delivery_id');
            $table->unsignedSmallInteger('sequence');
            $table->uuid('customer_id');
            $table->string('status', 10)->default('pending');
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampTz('done_at')->nullable();
            $table->string('receiver_name', 150)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['delivery_id', 'customer_id']);
        });
        TenantSchema::tenantKey('delivery_stops');
        TenantSchema::tenantForeign('delivery_stops', 'delivery_id', 'deliveries', 'CASCADE');
        TenantSchema::tenantForeign('delivery_stops', 'customer_id', 'customers');
        DB::statement("ALTER TABLE delivery_stops ADD CONSTRAINT delivery_stops_status_check CHECK (status IN ('pending','arrived','done','skipped'))");

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('delivery_id');
            $table->uuid('stop_id');
            $table->uuid('asset_id');
            $table->boolean('planned')->default(true);
            $table->string('state', 12)->default('planned');
            $table->boolean('active')->default(true);
            $table->timestampTz('loaded_at')->nullable();
            $table->uuid('loaded_by')->nullable();
            $table->timestampTz('unloaded_at')->nullable();
            $table->uuid('unloaded_by')->nullable();
            $table->timestampsTz();
            $table->unique(['delivery_id', 'asset_id']);
            $table->index(['organization_id', 'asset_id', 'updated_at']);
        });
        TenantSchema::tenantKey('delivery_items');
        TenantSchema::tenantForeign('delivery_items', 'delivery_id', 'deliveries', 'CASCADE');
        TenantSchema::tenantForeign('delivery_items', 'stop_id', 'delivery_stops', 'CASCADE');
        TenantSchema::tenantForeign('delivery_items', 'asset_id', 'assets');
        TenantSchema::memberForeign('delivery_items', 'loaded_by');
        TenantSchema::memberForeign('delivery_items', 'unloaded_by');
        DB::statement("ALTER TABLE delivery_items ADD CONSTRAINT delivery_items_state_check CHECK (state IN ('planned','loaded','unloaded','not_loaded','returned','missing','cancelled'))");
        DB::statement('CREATE UNIQUE INDEX delivery_items_one_open ON delivery_items (organization_id, asset_id) WHERE active');

        Schema::create('delivery_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('delivery_id');
            $table->uuid('stop_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->string('type', 12);
            $table->text('note')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->decimal('accuracy', 8, 1)->nullable();
            $table->uuid('client_id')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['delivery_id', 'recorded_at']);
        });
        TenantSchema::tenantForeign('delivery_events', 'delivery_id', 'deliveries');
        TenantSchema::tenantForeign('delivery_events', 'stop_id', 'delivery_stops');
        TenantSchema::tenantForeign('delivery_events', 'asset_id', 'assets');
        TenantSchema::memberForeign('delivery_events', 'actor_id');
        DB::statement("ALTER TABLE delivery_events ADD CONSTRAINT delivery_events_type_check CHECK (type IN ('created','updated','crew','load','unscan','depart','position','issue','resume','arrive','unload','stop_done','return','completed','cancelled'))");
        DB::statement('ALTER TABLE delivery_events ADD CONSTRAINT delivery_events_position_check CHECK ((latitude IS NULL) = (longitude IS NULL) AND (latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)))');
        // A report the phone sends again after a lost answer is recorded once.
        DB::statement('CREATE UNIQUE INDEX delivery_events_client ON delivery_events (delivery_id, client_id) WHERE client_id IS NOT NULL');
        TenantSchema::forbidUpdateDelete('delivery_events');

        foreach (['employees', 'customers', 'deliveries', 'delivery_stops', 'delivery_items'] as $table) {
            TenantSchema::enableRls($table);
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO {$app}");
        }
        TenantSchema::enableRls('delivery_events');
        DB::statement("GRANT SELECT, INSERT ON delivery_events TO {$app}");

        PermissionCatalog::sync();
        $permissions = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        foreach (DB::table('roles')->whereIn('template_code', array_keys(RoleTemplates::tenant()))->get(['id', 'organization_id']) as $role) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'organization_id' => $role->organization_id, 'role_id' => $role->id, 'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        foreach (['delivery_events', 'delivery_items', 'delivery_stops', 'deliveries', 'customers', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
        $ids = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
