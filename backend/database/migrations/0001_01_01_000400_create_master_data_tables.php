<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Master (platform-owned, versioned) and Tenant Custom Master (organization-owned).
 * See docs/03-database-erd.md §2.
 *
 * Database-level guarantees added here:
 *  - generic_* / units / location_types are writable only in platform (or system) context,
 *    so a tenant request can never modify the global catalog, even through a coding error;
 *  - released definition versions and their attributes are immutable (only released -> deprecated);
 *  - a tenant definition version can only pin a non-draft version of the generic definition it adopted;
 *  - tenant tables use composite foreign keys and Row-Level Security like the rest of the tenant data.
 */
return new class extends Migration
{
    private const DATA_TYPES = "'string','text','integer','decimal','boolean','date','enum','multi_enum'";

    public function up(): void
    {
        $this->createFunctions();

        // ------------------------------------------------------------------ Generic Master
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('symbol', 20);
            $table->string('quantity', 50)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        Schema::create('generic_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // 1 = best; higher = worse. Used for sorting and "needs attention" reports.
            $table->unsignedSmallInteger('severity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        // Behaviour of each type lives in code (Phase 5); the table is the catalog tenants configure against.
        Schema::create('generic_transaction_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        Schema::create('generic_asset_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('path')->default('');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->index('path');
        });
        // Self-reference added after the table (and its primary key) exists.
        Schema::table('generic_asset_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('generic_asset_categories');
        });

        Schema::create('generic_asset_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('generic_asset_categories');
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        Schema::create('generic_asset_definition_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_id')->constrained('generic_asset_definitions');
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->text('changelog')->nullable();
            $table->text('migration_notes')->nullable();
            $table->string('schema_hash', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestampTz('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users');
            $table->timestampTz('deprecated_at')->nullable();
            $table->foreignUuid('deprecated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['definition_id', 'version_number']);
        });
        DB::statement("CREATE UNIQUE INDEX generic_versions_one_draft ON generic_asset_definition_versions (definition_id) WHERE status = 'draft'");

        Schema::create('generic_attribute_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_version_id')->constrained('generic_asset_definition_versions');
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('help_text', 255)->nullable();
            $table->string('data_type', 16);
            $table->boolean('is_required')->default(false);
            $table->jsonb('validation')->default('{}');
            $table->jsonb('options')->nullable();
            $table->foreignUuid('unit_id')->nullable()->constrained('units');
            $table->jsonb('default_value')->nullable();
            $table->boolean('is_searchable')->default(false);
            // Controlled attributes change only through a CORRECTION transaction (Phase 5).
            $table->boolean('is_controlled')->default(false);
            // Properties a tenant may override, a subset of the system allowlist.
            $table->jsonb('overridable')->default('[]');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['definition_version_id', 'key']);
        });

        // ------------------------------------------------------------------ Tenant Custom Master
        Schema::create('tenant_asset_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('parent_id')->nullable();
            // Every tenant category specializes exactly one generic category.
            $table->foreignUuid('generic_category_id')->constrained('generic_asset_categories');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('path')->default('');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'path']);
        });
        TenantSchema::tenantKey('tenant_asset_categories');
        TenantSchema::tenantForeign('tenant_asset_categories', 'parent_id', 'tenant_asset_categories');

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

        Schema::create('tenant_asset_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('generic_definition_id')->constrained('generic_asset_definitions');
            $table->uuid('tenant_category_id')->nullable();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('tenant_asset_definitions');
        TenantSchema::tenantForeign('tenant_asset_definitions', 'tenant_category_id', 'tenant_asset_categories');
        TenantSchema::tenantForeign('tenant_asset_definitions', 'created_by', 'users');

        Schema::create('tenant_asset_definition_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('tenant_definition_id');
            $table->foreignUuid('generic_definition_version_id')->constrained('generic_asset_definition_versions');
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->text('changelog')->nullable();
            // Frozen at release: generic attributes + allowed overrides + tenant attributes.
            $table->jsonb('effective_schema')->nullable();
            $table->string('schema_hash', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampTz('deprecated_at')->nullable();
            $table->uuid('deprecated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_definition_id', 'version_number']);
        });
        TenantSchema::tenantKey('tenant_asset_definition_versions');
        TenantSchema::tenantForeign('tenant_asset_definition_versions', 'tenant_definition_id', 'tenant_asset_definitions');
        TenantSchema::tenantForeign('tenant_asset_definition_versions', 'created_by', 'users');
        TenantSchema::tenantForeign('tenant_asset_definition_versions', 'released_by', 'users');
        TenantSchema::tenantForeign('tenant_asset_definition_versions', 'deprecated_by', 'users');
        DB::statement("CREATE UNIQUE INDEX tenant_versions_one_draft ON tenant_asset_definition_versions (tenant_definition_id) WHERE status = 'draft'");
        DB::statement("ALTER TABLE tenant_asset_definition_versions ADD CONSTRAINT tenant_versions_released_check CHECK (
            status = 'draft' OR (effective_schema IS NOT NULL AND schema_hash IS NOT NULL AND released_at IS NOT NULL))");

        Schema::create('tenant_attribute_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('tenant_definition_version_id');
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('help_text', 255)->nullable();
            $table->string('data_type', 16);
            $table->boolean('is_required')->default(false);
            $table->jsonb('validation')->default('{}');
            $table->jsonb('options')->nullable();
            $table->foreignUuid('unit_id')->nullable()->constrained('units');
            $table->jsonb('default_value')->nullable();
            $table->boolean('is_searchable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_definition_version_id', 'key']);
        });
        TenantSchema::tenantKey('tenant_attribute_definitions');
        TenantSchema::tenantForeign('tenant_attribute_definitions', 'tenant_definition_version_id', 'tenant_asset_definition_versions');

        Schema::create('tenant_attribute_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('tenant_definition_version_id');
            $table->string('generic_attribute_key', 50);
            $table->jsonb('overrides');
            $table->timestampsTz();
            $table->unique(['tenant_definition_version_id', 'generic_attribute_key']);
        });
        TenantSchema::tenantKey('tenant_attribute_overrides');
        TenantSchema::tenantForeign('tenant_attribute_overrides', 'tenant_definition_version_id', 'tenant_asset_definition_versions');

        $this->addConstraintsAndTriggers();
    }

    private function createFunctions(): void
    {
        // Statement-level guard: global catalog tables accept writes only in platform/system context.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_require_platform_context() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT ate_platform_context() THEN
                    RAISE EXCEPTION 'Table % is writable only by the platform', TG_TABLE_NAME USING ERRCODE = '42501';
                END IF;
                RETURN NULL;
            END $$
        SQL);

        // Definition versions: draft is editable; release is one-way; afterwards only released -> deprecated.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_definition_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                lifecycle text[] := ARRAY['status', 'deprecated_at', 'deprecated_by', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION '% % is immutable after release', TG_TABLE_NAME, OLD.id USING ERRCODE = 'P0001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'draft' THEN
                    IF NEW.status NOT IN ('draft', 'released') THEN
                        RAISE EXCEPTION 'A draft version can only be released' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status = 'released' AND NEW.status = 'deprecated'
                   AND (to_jsonb(NEW) - lifecycle) = (to_jsonb(OLD) - lifecycle) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION '% % is immutable after release', TG_TABLE_NAME, OLD.id USING ERRCODE = 'P0001';
            END $$
        SQL);

        // Attribute rows follow their version: writable only while the version is a draft.
        // TG_ARGV: parent table, foreign key column.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_definition_child_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_status text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    EXECUTE format('SELECT status FROM %I WHERE id = $1', TG_ARGV[0])
                        INTO parent_status USING (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
                    -- NULL on DELETE: the parent draft itself is being removed.
                    IF parent_status IS DISTINCT FROM 'draft' AND NOT (TG_OP = 'DELETE' AND parent_status IS NULL) THEN
                        RAISE EXCEPTION 'Attributes of a released version are immutable' USING ERRCODE = 'P0001';
                    END IF;
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    EXECUTE format('SELECT status FROM %I WHERE id = $1', TG_ARGV[0])
                        INTO parent_status USING (to_jsonb(NEW) ->> TG_ARGV[1])::uuid;
                    IF parent_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'Attributes can only be added to a draft version' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END $$
        SQL);

        // Columns listed in TG_ARGV never change after insert.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_immutable_columns() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                col text;
            BEGIN
                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF (to_jsonb(NEW) -> col) IS DISTINCT FROM (to_jsonb(OLD) -> col) THEN
                        RAISE EXCEPTION '%.% is immutable', TG_TABLE_NAME, col USING ERRCODE = 'P0001';
                    END IF;
                END LOOP;
                RETURN NEW;
            END $$
        SQL);

        // A tenant version pins a released/deprecated version of the generic definition its tenant definition adopted.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_tenant_version_generic_check() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM generic_asset_definition_versions gv
                    JOIN tenant_asset_definitions td ON td.generic_definition_id = gv.definition_id
                    WHERE gv.id = NEW.generic_definition_version_id
                      AND td.id = NEW.tenant_definition_id
                      AND td.organization_id = NEW.organization_id
                      AND gv.status <> 'draft'
                ) THEN
                    RAISE EXCEPTION 'Tenant version must pin a released version of the adopted generic definition' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
    }

    private function addConstraintsAndTriggers(): void
    {
        foreach (['units', 'generic_conditions', 'generic_transaction_types', 'generic_asset_categories',
            'generic_asset_definitions', 'tenant_asset_categories', 'asset_conditions', 'tenant_asset_definitions'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_status_check CHECK (status IN ('active','inactive','archived'))");
        }
        foreach (['generic_asset_definition_versions', 'tenant_asset_definition_versions'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_status_check CHECK (status IN ('draft','released','deprecated'))");
            DB::statement("CREATE TRIGGER {$t}_guard BEFORE UPDATE OR DELETE ON {$t}
                FOR EACH ROW EXECUTE FUNCTION ate_definition_version_guard()");
        }
        foreach (['generic_attribute_definitions', 'tenant_attribute_definitions'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_type_check CHECK (data_type IN (".self::DATA_TYPES.'))');
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_key_format CHECK (key ~ '^[a-z][a-z0-9_]{0,49}$')");
        }
        DB::statement("CREATE TRIGGER generic_attribute_definitions_guard BEFORE INSERT OR UPDATE OR DELETE ON generic_attribute_definitions
            FOR EACH ROW EXECUTE FUNCTION ate_definition_child_guard('generic_asset_definition_versions', 'definition_version_id')");
        foreach (['tenant_attribute_definitions', 'tenant_attribute_overrides'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$t}
                FOR EACH ROW EXECUTE FUNCTION ate_definition_child_guard('tenant_asset_definition_versions', 'tenant_definition_version_id')");
        }

        DB::statement('CREATE TRIGGER tenant_versions_generic_check BEFORE INSERT OR UPDATE OF generic_definition_version_id, tenant_definition_id
            ON tenant_asset_definition_versions FOR EACH ROW EXECUTE FUNCTION ate_tenant_version_generic_check()');
        DB::statement("CREATE TRIGGER tenant_asset_definitions_immutable BEFORE UPDATE ON tenant_asset_definitions
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('generic_definition_id', 'code')");
        DB::statement("CREATE TRIGGER tenant_asset_categories_immutable BEFORE UPDATE ON tenant_asset_categories
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('generic_category_id', 'code')");
        DB::statement("CREATE TRIGGER generic_asset_definitions_immutable BEFORE UPDATE ON generic_asset_definitions
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('code')");

        foreach (['units', 'location_types', 'generic_conditions', 'generic_transaction_types', 'generic_asset_categories',
            'generic_asset_definitions', 'generic_asset_definition_versions', 'generic_attribute_definitions'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_platform_only BEFORE INSERT OR UPDATE OR DELETE ON {$t}
                FOR EACH STATEMENT EXECUTE FUNCTION ate_require_platform_context()");
        }

        foreach (['tenant_asset_categories', 'asset_conditions', 'tenant_asset_definitions', 'tenant_asset_definition_versions',
            'tenant_attribute_definitions', 'tenant_attribute_overrides'] as $t) {
            TenantSchema::enableRls($t);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS location_types_platform_only ON location_types');
        foreach (['tenant_attribute_overrides', 'tenant_attribute_definitions', 'tenant_asset_definition_versions',
            'tenant_asset_definitions', 'asset_conditions', 'tenant_asset_categories', 'generic_attribute_definitions',
            'generic_asset_definition_versions', 'generic_asset_definitions', 'generic_asset_categories',
            'generic_transaction_types', 'generic_conditions', 'units'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['ate_tenant_version_generic_check', 'ate_immutable_columns', 'ate_definition_child_guard',
            'ate_definition_version_guard', 'ate_require_platform_context'] as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
        }
    }
};
