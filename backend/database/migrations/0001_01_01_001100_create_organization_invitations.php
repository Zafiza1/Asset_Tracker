<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization invitations: an account only joins an organization after its owner accepted
 * an invitation sent to its (verified) email address. Nothing becomes a membership before that.
 *
 *  - organization_invitations: one row per invitation. Only the SHA-256 hash of the token is
 *    stored; at most one pending invitation per (organization, email). The requested access
 *    (role ids, data scopes) is a snapshot that is validated again when the invitation is
 *    accepted, so a role deleted or a reference archived in the meantime cannot slip through.
 *    The inviter, the revoker and the accepting account are members of the same organization
 *    (composite foreign keys). Rows are never deleted; they end as accepted, revoked or expired.
 *  - users.email_verified_at: when the account owner proved access to the email address.
 *    Existing accounts start unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('email_verified_at')->nullable();
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->char('token_hash', 64)->unique();
            $table->uuid('invited_by');
            $table->jsonb('role_ids');
            $table->jsonb('scopes');
            $table->string('employee_number', 50)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->uuid('home_department_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('last_sent_at')->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestampTz('accepted_at')->nullable();
            $table->uuid('accepted_user_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });
        DB::statement('ALTER TABLE organization_invitations ADD COLUMN email citext NOT NULL');
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_status_check
            CHECK (status IN ('pending', 'accepted', 'revoked', 'expired'))");
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_state_check CHECK (
            (status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL)) AND
            (status <> 'revoked' OR revoked_at IS NOT NULL))");
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_access_check
            CHECK (jsonb_typeof(role_ids) = 'array' AND jsonb_array_length(role_ids) > 0 AND jsonb_typeof(scopes) = 'array')");
        DB::statement("CREATE UNIQUE INDEX organization_invitations_pending_unique ON organization_invitations (organization_id, email)
            WHERE status = 'pending'");
        TenantSchema::tenantKey('organization_invitations');
        foreach (['invited_by', 'revoked_by', 'accepted_user_id'] as $column) {
            DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_{$column}_tfk
                FOREIGN KEY (organization_id, {$column}) REFERENCES organization_members (organization_id, user_id)");
        }
        TenantSchema::tenantForeign('organization_invitations', 'home_department_id', 'departments');
        DB::statement("CREATE TRIGGER organization_invitations_immutable BEFORE UPDATE ON organization_invitations
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('organization_id', 'email')");
        // Accepted and revoked invitations are final: they can never be reopened or reused.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_invitation_final_state() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('accepted', 'revoked') THEN
                    RAISE EXCEPTION 'Invitation % is already %', OLD.id, OLD.status USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER organization_invitations_final_state BEFORE UPDATE ON organization_invitations
            FOR EACH ROW EXECUTE FUNCTION ate_invitation_final_state()');
        DB::statement('CREATE TRIGGER organization_invitations_no_delete BEFORE DELETE ON organization_invitations
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');
        TenantSchema::enableRls('organization_invitations');
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('organization_invitations');
        DB::statement('DROP FUNCTION IF EXISTS ate_invitation_final_state()');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
