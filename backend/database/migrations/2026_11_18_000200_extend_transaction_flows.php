<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rest of the flow engine (docs/factory/features/F-07 §3.2, §4.1, F-07b):
 *
 *  - transaction_tasks: an approval may need several approvers (required_approvals; who
 *    approved so far in approved_by), and may go to the location supervisors of the run's
 *    assets (assignee_type location_supervisor, no assignee_id: whoever may approve and whose
 *    data scope covers the assets).
 *  - transaction_attachments: files of attachment fields. Uploaded first, then referenced by
 *    id in a field value; a referenced file is claimed by its run or transaction number and
 *    from then on readable by whoever sees that transaction. Unclaimed files are pruned.
 */
return new class extends Migration
{
    /** Field types before this migration; 'attachment' is added. */
    private const FIELD_TYPES = "'text','textarea','number','money','date','datetime','select','multiselect','boolean','user','location','counterparty'";

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        Schema::table('transaction_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->jsonb('approved_by')->default('[]');
        });
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_type TYPE varchar(20)');
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_id DROP NOT NULL');
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT transaction_tasks_assignee_check');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_assignee_check CHECK (
            (assignee_type IN ('role','user') AND assignee_id IS NOT NULL)
            OR (assignee_type = 'location_supervisor' AND assignee_id IS NULL AND kind = 'approval'))");
        DB::statement('ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_approvals_check CHECK (required_approvals BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_approved_by_check CHECK (jsonb_typeof(approved_by) = 'array')");

        DB::statement('ALTER TABLE transaction_type_fields DROP CONSTRAINT transaction_type_fields_type_check');
        DB::statement('ALTER TABLE transaction_type_fields ADD CONSTRAINT transaction_type_fields_type_check CHECK (data_type IN ('.self::FIELD_TYPES.", 'attachment'))");

        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('type_definition_id');
            $table->string('field_key', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->uuid('uploaded_by');
            $table->uuid('run_id')->nullable();
            $table->string('transaction_number', 60)->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'claimed_at']);
        });
        TenantSchema::tenantKey('transaction_attachments');
        TenantSchema::tenantForeign('transaction_attachments', 'type_definition_id', 'transaction_type_definitions');
        TenantSchema::tenantForeign('transaction_attachments', 'run_id', 'transaction_runs');
        TenantSchema::memberForeign('transaction_attachments', 'uploaded_by');
        DB::statement('ALTER TABLE transaction_attachments ADD CONSTRAINT transaction_attachments_claim_check CHECK (
            (claimed_at IS NULL) = (run_id IS NULL AND transaction_number IS NULL))');
        TenantSchema::enableRls('transaction_attachments');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_attachments TO {$app}");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('transaction_attachments');
        DB::statement("DELETE FROM transaction_type_fields WHERE data_type = 'attachment'");
        DB::statement('ALTER TABLE transaction_type_fields DROP CONSTRAINT transaction_type_fields_type_check');
        DB::statement('ALTER TABLE transaction_type_fields ADD CONSTRAINT transaction_type_fields_type_check CHECK (data_type IN ('.self::FIELD_TYPES.'))');
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT IF EXISTS transaction_tasks_approved_by_check');
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT IF EXISTS transaction_tasks_approvals_check');
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT IF EXISTS transaction_tasks_assignee_check');
        DB::statement("DELETE FROM transaction_tasks WHERE assignee_type = 'location_supervisor'");
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_id SET NOT NULL');
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_type TYPE varchar(10)');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_assignee_check CHECK (assignee_type IN ('role','user'))");
        Schema::table('transaction_tasks', fn (Blueprint $table) => $table->dropColumn(['required_approvals', 'approved_by']));
    }
};
