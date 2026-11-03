<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Location types ("jenis lokasi") are not used: nothing depends on them beyond a required
 * label on each location. Removes the location's type reference and the location_types
 * table (built-in and organization-owned types) together with their triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP TRIGGER locations_location_type_check ON locations');
        DB::statement('DROP FUNCTION ate_location_type_reference_check()');
        Schema::table('locations', fn (Blueprint $table) => $table->dropConstrainedForeignId('location_type_id'));

        Schema::drop('location_types');
        DB::statement('DROP FUNCTION ate_location_type_owner_guard()');
    }

    /** Restores the structure (not the data) of location types; locations keep no type. */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('location_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE location_types ADD CONSTRAINT location_types_status_check CHECK (status IN ('active','inactive','archived'))");
        DB::statement('CREATE UNIQUE INDEX location_types_global_code_unique ON location_types (code) WHERE organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX location_types_org_code_unique ON location_types (organization_id, code) WHERE organization_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE FUNCTION ate_location_type_owner_guard() RETURNS trigger
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
        DB::statement('CREATE POLICY location_types_visibility ON location_types
            USING (organization_id IS NULL OR organization_id = ate_current_org() OR ate_platform_context())
            WITH CHECK (organization_id IS NULL OR organization_id = ate_current_org() OR ate_platform_context())');

        Schema::table('locations', fn (Blueprint $table) => $table->foreignUuid('location_type_id')->nullable()->constrained('location_types'));

        DB::statement(<<<'SQL'
            CREATE FUNCTION ate_location_type_reference_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.location_type_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM location_types t WHERE t.id = NEW.location_type_id
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
};
