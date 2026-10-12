<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets and their transactions (docs/08-asset-tracker-sederhana.md).
 *
 * Database-level guarantees added here:
 *  - the internal identity of an asset (id, asset_number) never changes and assets are never deleted;
 *  - transactions are append-only: a mistake is fixed with a new transaction, never by editing history;
 *  - every reference (category, location, condition, user) stays inside the same organization
 *    (composite foreign keys) and Row-Level Security isolates tenants like the rest of the tenant data.
 */
return new class extends Migration
{
    private const STATUSES = "'available','in_use','on_loan','under_maintenance','disposed'";

    private const TRANSACTION_TYPES = "'registration','transfer','status_change','loan','return','note','archive','restore'";

    public function up(): void
    {
        // Gap-free, never reused counters per organization (asset numbers, transaction numbers).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('sequence_key', 30);
            $table->string('period_key', 10)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestampsTz();
            $table->primary(['organization_id', 'sequence_key', 'period_key']);
        });
        TenantSchema::enableRls('number_sequences');

        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            // System-generated, unique per organization (archived assets included), never reused.
            $table->string('asset_number', 60);
            // Organization's own serial number / asset code, entered by the user.
            $table->string('serial_number', 100)->nullable();
            $table->string('name', 150);
            $table->uuid('category_id');
            $table->uuid('location_id')->nullable();
            $table->uuid('condition_id')->nullable();
            $table->string('status', 20)->default('available');
            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_cost', 18, 2)->nullable();
            $table->text('description')->nullable();
            // Current loan, maintained by loan/return transactions.
            $table->string('borrower_name', 150)->nullable();
            $table->date('loan_due_date')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'asset_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['organization_id', 'location_id']);
        });
        TenantSchema::tenantKey('assets');
        TenantSchema::tenantForeign('assets', 'category_id', 'tenant_asset_categories');
        TenantSchema::tenantForeign('assets', 'location_id', 'locations');
        TenantSchema::tenantForeign('assets', 'condition_id', 'asset_conditions');
        foreach (['archived_by', 'created_by', 'updated_by'] as $column) {
            TenantSchema::tenantForeign('assets', $column, 'users');
        }
        DB::statement('CREATE UNIQUE INDEX assets_serial_unique ON assets (organization_id, lower(serial_number)) WHERE serial_number IS NOT NULL');
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ('.self::STATUSES.'))');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_loan_check CHECK (status = 'on_loan' OR (borrower_name IS NULL AND loan_due_date IS NULL))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_cost_check CHECK (acquisition_cost IS NULL OR acquisition_cost >= 0)');
        DB::statement("CREATE TRIGGER assets_immutable BEFORE UPDATE ON assets
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('asset_number', 'created_by')");
        DB::statement('CREATE TRIGGER assets_no_delete BEFORE DELETE ON assets
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');
        TenantSchema::enableRls('assets');

        Schema::create('asset_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('transaction_number', 60);
            $table->uuid('asset_id');
            $table->string('type', 20);
            $table->date('transaction_date');
            // Snapshot of what changed; NULL pairs mean "not touched by this transaction".
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->uuid('from_condition_id')->nullable();
            $table->uuid('to_condition_id')->nullable();
            $table->string('borrower_name', 150)->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'transaction_number']);
            $table->index(['organization_id', 'asset_id', 'created_at']);
            $table->index(['organization_id', 'transaction_date']);
            $table->index(['organization_id', 'type']);
        });
        // Recording order (created_at has second precision); history replays in this order.
        DB::statement('ALTER TABLE asset_transactions ADD COLUMN seq bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE INDEX asset_transactions_asset_seq ON asset_transactions (asset_id, seq)');
        TenantSchema::tenantKey('asset_transactions');
        TenantSchema::tenantForeign('asset_transactions', 'asset_id', 'assets');
        foreach (['from_location_id', 'to_location_id'] as $column) {
            TenantSchema::tenantForeign('asset_transactions', $column, 'locations');
        }
        foreach (['from_condition_id', 'to_condition_id'] as $column) {
            TenantSchema::tenantForeign('asset_transactions', $column, 'asset_conditions');
        }
        TenantSchema::tenantForeign('asset_transactions', 'created_by', 'users');
        DB::statement('ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_type_check CHECK (type IN ('.self::TRANSACTION_TYPES.'))');
        foreach (['from_status', 'to_status'] as $column) {
            DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_{$column}_check CHECK ({$column} IS NULL OR {$column} IN (".self::STATUSES.'))');
        }
        TenantSchema::forbidUpdateDelete('asset_transactions');
        TenantSchema::enableRls('asset_transactions');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transactions');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('number_sequences');
    }
};
