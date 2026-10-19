<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset conditions ("kondisi aset") are not used: the asset status is enough. Removes the
 * tenant and generic condition tables and every reference to them (the asset's current
 * condition and the condition pair recorded by transactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_condition_id_tfk');
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('condition_id'));

        foreach (['from_condition_id', 'to_condition_id'] as $column) {
            DB::statement("ALTER TABLE asset_transactions DROP CONSTRAINT asset_transactions_{$column}_tfk");
        }
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->dropColumn(['from_condition_id', 'to_condition_id']));

        Schema::drop('asset_conditions');
        Schema::drop('generic_conditions');

        DB::table('generic_transaction_types')->where('code', 'STATUS_CHANGE')
            ->update(['name' => 'Perubahan Status', 'description' => 'Mengubah status operasional aset.']);
    }

    /** Restores the structure (not the data) of asset conditions. */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('generic_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('severity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE generic_conditions ADD CONSTRAINT generic_conditions_status_check CHECK (status IN ('active','inactive','archived'))");
        DB::statement('CREATE TRIGGER generic_conditions_platform_only BEFORE INSERT OR UPDATE OR DELETE ON generic_conditions
            FOR EACH STATEMENT EXECUTE FUNCTION ate_require_platform_context()');

        Schema::create('asset_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('generic_condition_id')->nullable()->constrained('generic_conditions');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('severity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('asset_conditions');
        DB::statement('CREATE UNIQUE INDEX asset_conditions_generic_unique ON asset_conditions (organization_id, generic_condition_id) WHERE generic_condition_id IS NOT NULL');
        DB::statement("ALTER TABLE asset_conditions ADD CONSTRAINT asset_conditions_status_check CHECK (status IN ('active','inactive','archived'))");
        TenantSchema::enableRls('asset_conditions');

        Schema::table('assets', fn (Blueprint $table) => $table->uuid('condition_id')->nullable());
        TenantSchema::tenantForeign('assets', 'condition_id', 'asset_conditions');
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->uuid('from_condition_id')->nullable();
            $table->uuid('to_condition_id')->nullable();
        });
        foreach (['from_condition_id', 'to_condition_id'] as $column) {
            TenantSchema::tenantForeign('asset_transactions', $column, 'asset_conditions');
        }

        DB::table('generic_transaction_types')->where('code', 'STATUS_CHANGE')
            ->update(['name' => 'Perubahan Status/Kondisi', 'description' => 'Mengubah status operasional atau kondisi aset.']);
    }
};
