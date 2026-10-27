<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licensing: an organization works only under a valid Asset Tracker (base) license, and the
 * number of people who can use it is limited by seats.
 *
 *  - organization_licenses: one row per purchase, recorded by the platform. kind 'base' is the
 *    Asset Tracker license itself and carries 1 seat (its administrator); kind 'seats' adds
 *    seats for further members. Each row is valid from starts_at until expires_at, unless revoked.
 *  - Seats are not tied to people: an active member or a pending invitation uses one, and
 *    suspending/deactivating the member or revoking the invitation frees it for somebody else.
 *  - Only the platform/system context writes licenses; the organization reads its own rows.
 *    Rows are never deleted; the terms of a purchase never change, only a revocation is added.
 *  - Existing organizations get a base license and enough seats for their current members,
 *    valid for one year, so nobody loses access when this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        Schema::create('organization_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('kind', 16);
            $table->unsignedInteger('seats');
            $table->string('source', 16)->default('purchase');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->foreignUuid('granted_by')->nullable()->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->string('revoke_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'expires_at']);
        });
        DB::statement("ALTER TABLE organization_licenses ADD CONSTRAINT organization_licenses_kind_check CHECK (
            (kind = 'base' AND seats = 1) OR (kind = 'seats' AND seats > 0))");
        DB::statement("ALTER TABLE organization_licenses ADD CONSTRAINT organization_licenses_source_check
            CHECK (source IN ('purchase', 'trial', 'migration'))");
        DB::statement('ALTER TABLE organization_licenses ADD CONSTRAINT organization_licenses_period_check CHECK (expires_at > starts_at)');
        DB::statement('ALTER TABLE organization_licenses ADD CONSTRAINT organization_licenses_revoked_check
            CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))');
        TenantSchema::tenantKey('organization_licenses');

        DB::statement("CREATE TRIGGER organization_licenses_immutable BEFORE UPDATE ON organization_licenses
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('organization_id', 'kind', 'seats', 'source', 'starts_at', 'expires_at', 'granted_by', 'created_at')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_license_final_state() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'License % is already revoked', OLD.id USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER organization_licenses_final_state BEFORE UPDATE ON organization_licenses
            FOR EACH ROW EXECUTE FUNCTION ate_license_final_state()');
        DB::statement('CREATE TRIGGER organization_licenses_no_delete BEFORE DELETE ON organization_licenses
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');

        DB::statement('ALTER TABLE organization_licenses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE organization_licenses FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY organization_licenses_select ON organization_licenses FOR SELECT
            USING (organization_id = (SELECT ate_current_org()) OR (SELECT ate_platform_context()))');
        DB::statement('CREATE POLICY organization_licenses_insert ON organization_licenses FOR INSERT WITH CHECK ((SELECT ate_platform_context()))');
        DB::statement('CREATE POLICY organization_licenses_update ON organization_licenses FOR UPDATE
            USING ((SELECT ate_platform_context())) WITH CHECK ((SELECT ate_platform_context()))');
        DB::statement("GRANT SELECT, INSERT, UPDATE ON organization_licenses TO {$app}");

        // Seats in use today: active members and open invitations, at least the base seat.
        DB::statement(<<<'SQL'
            INSERT INTO organization_licenses (id, organization_id, kind, seats, source, notes, starts_at, expires_at, created_at, updated_at)
            SELECT gen_random_uuid(), o.id, 'base', 1, 'migration', 'Lisensi awal saat sistem lisensi diaktifkan', now(), now() + interval '1 year', now(), now()
            FROM organizations o
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO organization_licenses (id, organization_id, kind, seats, source, notes, starts_at, expires_at, created_at, updated_at)
            SELECT gen_random_uuid(), u.organization_id, 'seats', u.used - 1, 'migration', 'Seat untuk anggota yang sudah ada saat sistem lisensi diaktifkan', now(), now() + interval '1 year', now(), now()
            FROM (
                SELECT o.id AS organization_id,
                    (SELECT count(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active')
                    + (SELECT count(*) FROM organization_invitations i WHERE i.organization_id = o.id AND i.status = 'pending' AND i.expires_at > now()) AS used
                FROM organizations o
            ) u
            WHERE u.used > 1
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_licenses');
        DB::statement('DROP FUNCTION IF EXISTS ate_license_final_state()');
    }
};
