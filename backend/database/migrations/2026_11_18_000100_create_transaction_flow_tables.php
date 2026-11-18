<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flows of the organization's transaction types (docs/factory/features/F-07 §4–5, F-07b).
 *
 *  - transaction_flow_versions: the diagram (`graph`: nodes and edges) per type. A draft is
 *    edited, publishing freezes it (trigger) and makes it the type's active flow; running
 *    transactions keep the version they started with (D-28).
 *  - transaction_runs: a transaction of such a type while it goes through its flow: state,
 *    current step and the values entered so far. asset_transactions stays append-only; it gets
 *    the rows written by flow actions and at completion, linked by run_id.
 *  - transaction_run_assets: the run's assets. `locked` rows hold the asset (D-29): a partial
 *    unique index allows one locking run per asset, also under concurrency.
 *  - transaction_steps: what happened at each step (append-only history).
 *  - transaction_tasks: open work for people (fill in data, approve) at a step.
 *
 * Rows written by a run share the run's number, and one asset may get several of them (status
 * change, move, completion), so the per-asset number uniqueness now applies to rows outside runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        Schema::create('transaction_flow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('type_definition_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 10)->default('draft');
            $table->jsonb('graph');
            $table->text('change_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'type_definition_id', 'version_number']);
        });
        TenantSchema::tenantKey('transaction_flow_versions');
        TenantSchema::tenantForeign('transaction_flow_versions', 'type_definition_id', 'transaction_type_definitions', 'CASCADE');
        TenantSchema::memberForeign('transaction_flow_versions', 'created_by');
        TenantSchema::memberForeign('transaction_flow_versions', 'published_by');
        DB::statement("ALTER TABLE transaction_flow_versions ADD CONSTRAINT transaction_flow_versions_status_check CHECK (status IN ('draft','published'))");
        DB::statement("ALTER TABLE transaction_flow_versions ADD CONSTRAINT transaction_flow_versions_graph_check CHECK (jsonb_typeof(graph) = 'object')");
        DB::statement("ALTER TABLE transaction_flow_versions ADD CONSTRAINT transaction_flow_versions_publish_check CHECK ((status = 'published') = (published_at IS NOT NULL))");
        // One draft per type at a time.
        DB::statement("CREATE UNIQUE INDEX transaction_flow_versions_one_draft ON transaction_flow_versions (type_definition_id) WHERE status = 'draft'");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_transaction_flow_version_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published transaction flow versions are immutable' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER transaction_flow_versions_immutable BEFORE UPDATE OR DELETE ON transaction_flow_versions
            FOR EACH ROW EXECUTE FUNCTION ate_transaction_flow_version_immutable()');
        TenantSchema::enableRls('transaction_flow_versions');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_flow_versions TO {$app}");

        Schema::table('transaction_type_definitions', function (Blueprint $table) {
            $table->uuid('active_flow_version_id')->nullable();
        });
        TenantSchema::tenantForeign('transaction_type_definitions', 'active_flow_version_id', 'transaction_flow_versions');

        Schema::create('transaction_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('type_definition_id');
            $table->uuid('flow_version_id');
            $table->string('transaction_number', 60);
            $table->string('state', 12)->default('running');
            $table->string('current_node_id', 40)->nullable();
            $table->jsonb('field_values')->default('{}');
            $table->text('notes')->nullable();
            $table->date('transaction_date');
            $table->uuid('created_by');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'transaction_number']);
            $table->index(['organization_id', 'state']);
        });
        TenantSchema::tenantKey('transaction_runs');
        TenantSchema::tenantForeign('transaction_runs', 'type_definition_id', 'transaction_type_definitions');
        TenantSchema::tenantForeign('transaction_runs', 'flow_version_id', 'transaction_flow_versions');
        TenantSchema::memberForeign('transaction_runs', 'created_by');
        DB::statement("ALTER TABLE transaction_runs ADD CONSTRAINT transaction_runs_state_check CHECK (state IN ('running','completed','rejected','cancelled'))");
        DB::statement("ALTER TABLE transaction_runs ADD CONSTRAINT transaction_runs_end_check CHECK ((state = 'running') = (ended_at IS NULL))");
        DB::statement("ALTER TABLE transaction_runs ADD CONSTRAINT transaction_runs_values_check CHECK (jsonb_typeof(field_values) = 'object')");
        TenantSchema::enableRls('transaction_runs');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_runs TO {$app}");

        Schema::create('transaction_run_assets', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('run_id');
            $table->uuid('asset_id');
            $table->boolean('locked')->default(false);
            $table->primary(['run_id', 'asset_id']);
        });
        TenantSchema::tenantForeign('transaction_run_assets', 'run_id', 'transaction_runs', 'CASCADE');
        TenantSchema::tenantForeign('transaction_run_assets', 'asset_id', 'assets');
        DB::statement('CREATE UNIQUE INDEX transaction_run_assets_one_lock ON transaction_run_assets (organization_id, asset_id) WHERE locked');
        TenantSchema::enableRls('transaction_run_assets');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_run_assets TO {$app}");

        Schema::create('transaction_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('run_id');
            $table->string('node_id', 40);
            $table->string('node_type', 12);
            $table->string('outcome', 20)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['run_id', 'created_at']);
        });
        TenantSchema::tenantForeign('transaction_steps', 'run_id', 'transaction_runs', 'CASCADE');
        TenantSchema::memberForeign('transaction_steps', 'actor_id');
        DB::statement("ALTER TABLE transaction_steps ADD CONSTRAINT transaction_steps_details_check CHECK (jsonb_typeof(details) = 'object')");
        TenantSchema::forbidUpdateDelete('transaction_steps');
        TenantSchema::enableRls('transaction_steps');
        DB::statement("GRANT SELECT, INSERT ON transaction_steps TO {$app}");

        Schema::create('transaction_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('run_id');
            $table->string('node_id', 40);
            $table->string('kind', 10);
            $table->string('assignee_type', 10);
            $table->uuid('assignee_id');
            $table->string('status', 10)->default('open');
            $table->date('due_date')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status', 'assignee_type', 'assignee_id']);
        });
        TenantSchema::tenantKey('transaction_tasks');
        TenantSchema::tenantForeign('transaction_tasks', 'run_id', 'transaction_runs', 'CASCADE');
        TenantSchema::memberForeign('transaction_tasks', 'completed_by');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_kind_check CHECK (kind IN ('input','approval'))");
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_assignee_check CHECK (assignee_type IN ('role','user'))");
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_status_check CHECK (status IN ('open','done','cancelled'))");
        // One open task per run step.
        DB::statement("CREATE UNIQUE INDEX transaction_tasks_one_open ON transaction_tasks (run_id, node_id) WHERE status = 'open'");
        TenantSchema::enableRls('transaction_tasks');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_tasks TO {$app}");

        // Rows written by a run share its number; several rows per asset are allowed there.
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->uuid('run_id')->nullable();
        });
        TenantSchema::tenantForeign('asset_transactions', 'run_id', 'transaction_runs');
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->dropUnique(['organization_id', 'transaction_number', 'asset_id']));
        DB::statement('CREATE UNIQUE INDEX asset_transactions_number_asset_unique ON asset_transactions (organization_id, transaction_number, asset_id) WHERE run_id IS NULL');
        DB::statement('CREATE INDEX asset_transactions_run_idx ON asset_transactions (run_id) WHERE run_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP INDEX IF EXISTS asset_transactions_run_idx');
        DB::statement('DROP INDEX IF EXISTS asset_transactions_number_asset_unique');
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->unique(['organization_id', 'transaction_number', 'asset_id']));
        DB::statement('ALTER TABLE asset_transactions DROP CONSTRAINT IF EXISTS asset_transactions_run_id_tfk');
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->dropColumn('run_id'));

        Schema::dropIfExists('transaction_tasks');
        Schema::dropIfExists('transaction_steps');
        Schema::dropIfExists('transaction_run_assets');
        Schema::dropIfExists('transaction_runs');
        DB::statement('ALTER TABLE transaction_type_definitions DROP CONSTRAINT IF EXISTS transaction_type_definitions_active_flow_version_id_tfk');
        Schema::table('transaction_type_definitions', fn (Blueprint $table) => $table->dropColumn('active_flow_version_id'));
        DB::statement('DROP TRIGGER IF EXISTS transaction_flow_versions_immutable ON transaction_flow_versions');
        Schema::dropIfExists('transaction_flow_versions');
        DB::statement('DROP FUNCTION IF EXISTS ate_transaction_flow_version_immutable()');
    }
};
