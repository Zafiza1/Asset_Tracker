<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Report Designer (Phase 1).
 *
 *  - report_templates: an organization's report templates. Owned by the organization, never
 *    by the creator: they survive the creator leaving. Soft-deleted; `code` is unique per
 *    organization among live templates. `active_version` is the version number new exports
 *    use; NULL until the first publish.
 *  - report_template_versions: immutable snapshots of the designer document (`design`) and
 *    the Excel configuration (`excel_config`). A version starts as a draft; publishing makes
 *    it immutable (trigger) and points the template's active_version at it. Exports keep
 *    their own reference, so later versions never rewrite history.
 *  - report_template_assets: images and signature pictures uploaded for a template.
 *  - report_field_registry: the official field list per data source (reference data written
 *    only in the system context, read by every organization, like widget_catalog).
 *  - report_exports / report_export_files: generation history. Each export pins the exact
 *    template version it rendered, so results stay reproducible after the template changes.
 *    The applied filters are stored as a JSON snapshot on the export itself; a separate
 *    report_export_filters table was evaluated and skipped — no query ever needs to search
 *    inside a single export's filter set.
 *
 * Isolation: tenant rows carry organization_id with composite foreign keys and forced RLS,
 * exactly like the dashboard tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        $this->createTemplates($app);
        $this->createVersions($app);
        $this->createTemplateAssets($app);
        $this->createFieldRegistry($app);
        $this->createExports($app);
        $this->createExportFiles($app);
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        foreach (['report_export_files', 'report_exports', 'report_field_registry', 'report_template_assets',
            'report_template_versions', 'report_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['ate_report_template_version_immutable()', 'ate_report_template_asset_owner()'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}");
        }
    }

    private function createTemplates(string $app): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 60);
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Free grouping label for the template list (e.g. 'operasional', 'inventaris').
            $table->string('category', 60)->nullable();
            // The registry source the template draws its rows from.
            $table->string('data_source', 40);
            $table->string('status', 16)->default('draft');
            // Version number used for new exports; NULL until the first publish.
            $table->unsignedInteger('active_version')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'data_source']);
        });
        TenantSchema::tenantKey('report_templates');
        TenantSchema::memberForeign('report_templates', 'created_by');
        DB::statement("ALTER TABLE report_templates ADD CONSTRAINT report_templates_status_check
            CHECK (status IN ('draft','published','archived'))");
        DB::statement("ALTER TABLE report_templates ADD CONSTRAINT report_templates_source_check
            CHECK (data_source IN ('asset','asset_transaction'))");
        DB::statement('ALTER TABLE report_templates ADD CONSTRAINT report_templates_version_check
            CHECK (active_version IS NULL OR active_version >= 1)');
        DB::statement('CREATE UNIQUE INDEX report_templates_code_unique ON report_templates (organization_id, code) WHERE deleted_at IS NULL');

        // Templates are soft-deleted: the runtime role gets no DELETE.
        TenantSchema::enableRls('report_templates');
        DB::statement("GRANT SELECT, INSERT, UPDATE ON report_templates TO {$app}");
    }

    private function createVersions(string $app): void
    {
        Schema::create('report_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('template_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->jsonb('design');
            $table->jsonb('excel_config')->default('{}');
            $table->text('change_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'template_id', 'version_number']);
        });
        TenantSchema::tenantKey('report_template_versions');
        TenantSchema::tenantForeign('report_template_versions', 'template_id', 'report_templates', 'CASCADE');
        TenantSchema::memberForeign('report_template_versions', 'created_by');
        TenantSchema::memberForeign('report_template_versions', 'published_by');
        DB::statement("ALTER TABLE report_template_versions ADD CONSTRAINT report_template_versions_status_check
            CHECK (status IN ('draft','published'))");
        DB::statement('ALTER TABLE report_template_versions ADD CONSTRAINT report_template_versions_number_check
            CHECK (version_number >= 1)');
        DB::statement("ALTER TABLE report_template_versions ADD CONSTRAINT report_template_versions_json_check
            CHECK (jsonb_typeof(design) = 'object' AND jsonb_typeof(excel_config) = 'object')");
        DB::statement("ALTER TABLE report_template_versions ADD CONSTRAINT report_template_versions_publish_check
            CHECK ((status = 'published') = (published_at IS NOT NULL))");

        // A published version is a frozen snapshot: no update, no delete (exports reference it).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_report_template_version_immutable() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'published' THEN
                        RAISE EXCEPTION 'Published report template versions cannot be deleted' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published report template versions are immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER report_template_versions_immutable BEFORE UPDATE OR DELETE ON report_template_versions
            FOR EACH ROW EXECUTE FUNCTION ate_report_template_version_immutable()');

        TenantSchema::enableRls('report_template_versions');
        // Versions are history: the runtime role may insert and update drafts, never delete.
        DB::statement("GRANT SELECT, INSERT, UPDATE ON report_template_versions TO {$app}");
    }

    private function createTemplateAssets(string $app): void
    {
        Schema::create('report_template_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('template_id');
            // NULL while the asset is only referenced by the working draft.
            $table->uuid('version_id')->nullable();
            $table->string('kind', 16);
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();
            $table->index(['template_id', 'kind']);
        });
        TenantSchema::tenantKey('report_template_assets');
        TenantSchema::tenantForeign('report_template_assets', 'template_id', 'report_templates', 'CASCADE');
        TenantSchema::tenantForeign('report_template_assets', 'version_id', 'report_template_versions', 'SET NULL (version_id)');
        TenantSchema::memberForeign('report_template_assets', 'uploaded_by');
        DB::statement("ALTER TABLE report_template_assets ADD CONSTRAINT report_template_assets_kind_check
            CHECK (kind IN ('image','signature'))");

        // An asset belongs to the organization of its template.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_report_template_asset_owner() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE owner uuid;
            BEGIN
                SELECT t.organization_id INTO owner FROM report_templates t WHERE t.id = NEW.template_id;
                IF NOT FOUND OR owner IS DISTINCT FROM NEW.organization_id THEN
                    RAISE EXCEPTION 'A report template asset belongs to the organization of its template' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER report_template_assets_owner BEFORE INSERT OR UPDATE ON report_template_assets
            FOR EACH ROW EXECUTE FUNCTION ate_report_template_asset_owner()');

        TenantSchema::enableRls('report_template_assets');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON report_template_assets TO {$app}");
    }

    private function createFieldRegistry(string $app): void
    {
        Schema::create('report_field_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('data_source', 40);
            $table->string('field_key', 80);
            $table->string('label', 150);
            $table->string('data_type', 20);
            // Dot path the resolver walks from the source root (e.g. 'category.name').
            $table->string('bind_path', 160);
            // Relation the path crosses, for display and permission checks (e.g. 'category').
            $table->string('relation', 80)->nullable();
            // True for fields inside a repeating collection (e.g. transaction items).
            $table->boolean('is_repeatable')->default(false);
            // Formatting hints: allowed date/number formats and the empty-value fallback.
            $table->jsonb('formats')->default('{}');
            // Permission a member must hold for the field's data to resolve (NULL = any viewer).
            $table->string('required_permission', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['data_source', 'field_key']);
        });
        DB::statement("ALTER TABLE report_field_registry ADD CONSTRAINT report_field_registry_type_check
            CHECK (data_type IN ('string','number','currency','date','boolean','image','array','relation'))");
        DB::statement("ALTER TABLE report_field_registry ADD CONSTRAINT report_field_registry_json_check
            CHECK (jsonb_typeof(formats) = 'object')");
        // Reference data like widget_catalog: written only in the system context (seeder),
        // readable by every tenant.
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON report_field_registry TO {$app}");
        DB::statement('CREATE TRIGGER report_field_registry_system_only BEFORE INSERT OR UPDATE OR DELETE ON report_field_registry
            FOR EACH STATEMENT EXECUTE FUNCTION ate_require_system_context()');
    }

    private function createExports(string $app): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('template_id');
            // The exact version rendered; pinned so later template edits never change history.
            $table->uuid('template_version_id');
            $table->string('export_number', 40);
            $table->string('format', 8);
            $table->string('status', 16)->default('pending');
            // Snapshot of the filters the user applied (and their resolved descriptions).
            $table->jsonb('filters')->default('{}');
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'export_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
        TenantSchema::tenantKey('report_exports');
        TenantSchema::tenantForeign('report_exports', 'template_id', 'report_templates', 'RESTRICT');
        TenantSchema::tenantForeign('report_exports', 'template_version_id', 'report_template_versions', 'RESTRICT');
        TenantSchema::memberForeign('report_exports', 'created_by');
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('pdf','xlsx'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check
            CHECK (status IN ('pending','processing','completed','failed','expired'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_json_check
            CHECK (jsonb_typeof(filters) = 'object')");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_shape_check
            CHECK ((status IN ('pending','processing')) = (completed_at IS NULL))");

        TenantSchema::enableRls('report_exports');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON report_exports TO {$app}");
    }

    private function createExportFiles(string $app): void
    {
        Schema::create('report_export_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('export_id');
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestampsTz();
        });
        TenantSchema::tenantKey('report_export_files');
        TenantSchema::tenantForeign('report_export_files', 'export_id', 'report_exports', 'CASCADE');

        TenantSchema::enableRls('report_export_files');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON report_export_files TO {$app}");
    }
};
