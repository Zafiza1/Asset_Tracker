<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations can add their own location types next to the platform's built-in ones.
 *
 *  - location_types.organization_id: NULL = built-in (platform), otherwise owned by that organization;
 *  - RLS: a tenant sees the built-in types and its own; the row trigger keeps built-in rows
 *    writable only by the platform (tampering still fails with 42501, not silently);
 *  - a location can only use a built-in type or a type of its own organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE location_types ADD COLUMN organization_id uuid NULL REFERENCES organizations (id)');
        DB::statement('ALTER TABLE location_types DROP CONSTRAINT location_types_code_unique');
        DB::statement('CREATE UNIQUE INDEX location_types_global_code_unique ON location_types (code) WHERE organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX location_types_org_code_unique ON location_types (organization_id, code) WHERE organization_id IS NOT NULL');

        DB::statement('DROP TRIGGER location_types_platform_only ON location_types');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_location_type_owner_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
                    RAISE EXCEPTION 'location_types.organization_id is immutable' USING ERRCODE = '42501';
                END IF;
                IF NOT ate_platform_context() AND (
                    (TG_OP <> 'INSERT' AND OLD.organization_id IS NULL) OR (TG_OP <> 'DELETE' AND NEW.organization_id IS NULL)
                ) THEN
                    RAISE EXCEPTION 'Built-in location types are writable only by the platform' USING ERRCODE = '42501';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER location_types_owner_guard BEFORE INSERT OR UPDATE OR DELETE ON location_types
            FOR EACH ROW EXECUTE FUNCTION ate_location_type_owner_guard()');

        DB::statement('ALTER TABLE location_types ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE location_types FORCE ROW LEVEL SECURITY');
        // Built-in rows pass the policy so that writes to them reach the trigger and fail loudly.
        DB::statement('CREATE POLICY location_types_visibility ON location_types
            USING (organization_id IS NULL OR organization_id = ate_current_org() OR ate_platform_context())
            WITH CHECK (organization_id IS NULL OR organization_id = ate_current_org() OR ate_platform_context())');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_location_type_reference_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM location_types t WHERE t.id = NEW.location_type_id
                        AND (t.organization_id IS NULL OR t.organization_id = NEW.organization_id)) THEN
                    RAISE EXCEPTION 'Location type % does not belong to organization %', NEW.location_type_id, NEW.organization_id
                        USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER locations_location_type_check BEFORE INSERT OR UPDATE OF location_type_id, organization_id ON locations
            FOR EACH ROW EXECUTE FUNCTION ate_location_type_reference_check()');
    }

    /** Fails while organization-owned types are still referenced by locations. */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP TRIGGER locations_location_type_check ON locations');
        DB::statement('DROP FUNCTION ate_location_type_reference_check()');

        DB::table('location_types')->whereNotNull('organization_id')->delete();

        DB::statement('DROP POLICY location_types_visibility ON location_types');
        DB::statement('ALTER TABLE location_types NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE location_types DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP TRIGGER location_types_owner_guard ON location_types');
        DB::statement('DROP FUNCTION ate_location_type_owner_guard()');
        DB::statement('CREATE TRIGGER location_types_platform_only BEFORE INSERT OR UPDATE OR DELETE ON location_types
            FOR EACH STATEMENT EXECUTE FUNCTION ate_require_platform_context()');

        DB::statement('DROP INDEX location_types_org_code_unique');
        DB::statement('DROP INDEX location_types_global_code_unique');
        DB::statement('ALTER TABLE location_types ADD CONSTRAINT location_types_code_unique UNIQUE (code)');
        Schema::table('location_types', fn ($table) => $table->dropColumn('organization_id'));
    }
};
