<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * License codes: reusable activation codes for user accounts.
 *
 *  - license_codes: each code can be assigned to a user and released when they leave.
 *    Codes are globally unique (LIC-XXXX-XXXX-XXXX format) and can be reused across
 *    different users over time. When a user resigns, the code becomes available again.
 *  - The code is assigned during invitation and used during registration for activation.
 *  - Rows are never deleted; they track assignment history through status changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('license_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 19)->unique();
            $table->string('status', 16)->default('available');
            $table->uuid('assigned_to_user_id')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });

        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_status_check
            CHECK (status IN ('available', 'assigned', 'revoked'))");
        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_code_format_check
            CHECK (code ~ '^LIC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$')");
        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_assignment_check
            CHECK ((status = 'assigned' AND assigned_to_user_id IS NOT NULL AND assigned_at IS NOT NULL) OR
                   (status <> 'assigned' AND assigned_to_user_id IS NULL AND assigned_at IS NULL))");

        TenantSchema::tenantKey('license_codes');
        // Composite FK: (organization_id, assigned_to_user_id) -> organization_members (organization_id, user_id)
        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_assigned_to_user_id_tfk
            FOREIGN KEY (organization_id, assigned_to_user_id) REFERENCES organization_members (organization_id, user_id) ON DELETE RESTRICT");

        DB::statement('CREATE TRIGGER license_codes_immutable BEFORE UPDATE ON license_codes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns(\'id\', \'organization_id\', \'code\', \'created_at\')');
        DB::statement('CREATE TRIGGER license_codes_no_delete BEFORE DELETE ON license_codes
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');
        TenantSchema::enableRls('license_codes');

        $app = config('database.app_role');
        DB::statement("GRANT SELECT, INSERT, UPDATE ON license_codes TO {$app}");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('license_codes');
    }
};
