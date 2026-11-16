<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard Builder foundation (Phase 1).
 *
 *  - widget_catalog: the widgets the system offers (reference data, synced from SystemWidgets).
 *    The runtime role only reads it: no tenant can add a widget type or change its schema.
 *  - dashboard_templates / template_widgets: system templates (organization_id NULL, readable by
 *    every organization, written only in the system context) and organization templates.
 *  - dashboards / dashboard_widgets: dashboards hold copies of their template's widgets, so a new
 *    template version never rewrites an existing dashboard (template_version records the copy).
 *  - dashboard_shares: an organization dashboard shared with one member or one role.
 *  - user_dashboard_preferences: each member's default dashboard per organization.
 *
 * Isolation: every tenant row carries organization_id with composite foreign keys (categories,
 * members, roles and dashboards of another organization cannot be referenced) and forced RLS.
 * On top of the tenant policy, restrictive policies keep a personal dashboard (and its widgets
 * and shares) visible to its owner only, and preferences to the member they belong to.
 * Who may open or edit an organization dashboard (shares, permissions) is decided by the
 * application (DashboardPolicy); RLS is the backstop.
 */
return new class extends Migration
{
    private const ZERO_UUID = "'00000000-0000-0000-0000-000000000000'::uuid";

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        $this->createWidgetCatalog($app);
        $this->createTemplates($app);
        $this->createDashboards($app);
        $this->createDashboardWidgets($app);
        $this->createShares($app);
        $this->createPreferences($app);
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        foreach (['user_dashboard_preferences', 'dashboard_shares', 'dashboard_widgets', 'dashboards', 'template_widgets', 'dashboard_templates', 'widget_catalog'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['ate_template_widget_owner()', 'ate_dashboard_template_owner()', 'ate_dashboard_share_target()'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}");
        }
    }

    private function createWidgetCatalog(string $app): void
    {
        Schema::create('widget_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('widget_key', 60)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('component_type', 20);
            $table->jsonb('configuration_schema')->default('{}');
            $table->jsonb('supported_data_sources')->default('[]');
            $table->jsonb('default_layout')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE widget_catalog ADD CONSTRAINT widget_catalog_component_type_check
            CHECK (component_type IN ('statistic','chart','table','activity','status','reminder','location'))");
        DB::statement("ALTER TABLE widget_catalog ADD CONSTRAINT widget_catalog_json_check CHECK (
            jsonb_typeof(configuration_schema) = 'object' AND jsonb_typeof(supported_data_sources) = 'array'
            AND jsonb_typeof(default_layout) = 'object')");
        // Reference data like `permissions`: written only in the system context (seeder), read by every tenant.
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON widget_catalog TO {$app}");
        DB::statement('CREATE TRIGGER widget_catalog_system_only BEFORE INSERT OR UPDATE OR DELETE ON widget_catalog
            FOR EACH STATEMENT EXECUTE FUNCTION ate_require_system_context()');
    }

    private function createTemplates(string $app): void
    {
        Schema::create('dashboard_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = system template (scope 'system'), shared by every organization.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            // The system template an organization template was copied from.
            $table->uuid('source_template_id')->nullable();
            $table->string('template_key', 60);
            $table->string('name', 150);
            $table->text('description')->nullable();
            // The kind of dashboard the template builds.
            $table->string('dashboard_type', 16);
            // Category templates: the category type it fits (StandardCategories code or generic
            // category code); NULL = the generic category template. Never a tenant category id.
            $table->string('category_type_key', 60)->nullable();
            $table->string('scope', 16);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'dashboard_type', 'category_type_key']);
        });
        // Self-reference added after the table (and its primary key) exists.
        Schema::table('dashboard_templates', function (Blueprint $table) {
            $table->foreign('source_template_id')->references('id')->on('dashboard_templates')->nullOnDelete();
        });
        TenantSchema::tenantKey('dashboard_templates');
        TenantSchema::memberForeign('dashboard_templates', 'created_by');
        DB::statement("ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_scope_check CHECK (
            (scope = 'system' AND organization_id IS NULL AND created_by IS NULL) OR
            (scope = 'organization' AND organization_id IS NOT NULL))");
        DB::statement("ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_type_check CHECK (
            dashboard_type IN ('overview','category','custom') AND (category_type_key IS NULL OR dashboard_type = 'category'))");
        DB::statement('ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_version_check CHECK (version >= 1)');
        DB::statement('CREATE UNIQUE INDEX dashboard_templates_system_key ON dashboard_templates (template_key) WHERE organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX dashboard_templates_org_key ON dashboard_templates (organization_id, template_key) WHERE organization_id IS NOT NULL');
        // At most one active default per owner (system or organization), dashboard type and category type.
        DB::statement('CREATE UNIQUE INDEX dashboard_templates_default ON dashboard_templates
            (coalesce(organization_id, '.self::ZERO_UUID."), dashboard_type, coalesce(category_type_key, ''))
            WHERE is_default AND is_active");

        Schema::create('template_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Same owner as the template (NULL for system templates); checked by a trigger.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('template_id')->constrained('dashboard_templates')->cascadeOnDelete();
            $table->foreignUuid('widget_catalog_id')->constrained('widget_catalog');
            $table->string('title', 150)->nullable();
            $table->jsonb('configuration')->default('{}');
            $table->jsonb('layout')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['template_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE template_widgets ADD CONSTRAINT template_widgets_json_check
            CHECK (jsonb_typeof(configuration) = 'object' AND jsonb_typeof(layout) = 'object')");
        // A widget belongs to the owner of its template: a system template gets no organization's
        // widgets, and no organization adds widgets to another one's template (RLS hides it: NOT FOUND).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_template_widget_owner() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE owner uuid;
            BEGIN
                SELECT t.organization_id INTO owner FROM dashboard_templates t WHERE t.id = NEW.template_id;
                IF NOT FOUND OR owner IS DISTINCT FROM NEW.organization_id THEN
                    RAISE EXCEPTION 'A template widget belongs to the organization of its template' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER template_widgets_owner BEFORE INSERT OR UPDATE ON template_widgets
            FOR EACH ROW EXECUTE FUNCTION ate_template_widget_owner()');

        foreach (['dashboard_templates', 'template_widgets'] as $table) {
            $this->sharedReferenceRls($table);
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO {$app}");
        }
    }

    private function createDashboards(string $app): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            // NULL for dashboards the system creates (Overview, category dashboards).
            $table->uuid('created_by')->nullable();
            $table->foreignUuid('template_id')->nullable()->constrained('dashboard_templates')->nullOnDelete();
            // Template version the widgets were copied from; updating to a newer one is explicit.
            $table->unsignedInteger('template_version')->nullable();
            $table->uuid('category_id')->nullable();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('dashboard_type', 16);
            $table->string('visibility', 16);
            $table->string('status', 16)->default('active');
            // The organization's default dashboard (the Overview unless changed).
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'dashboard_type']);
            $table->index(['organization_id', 'created_by']);
        });
        TenantSchema::tenantKey('dashboards');
        TenantSchema::memberForeign('dashboards', 'created_by');
        // A category dashboard goes with its category (categories are only deleted while unused).
        TenantSchema::tenantForeign('dashboards', 'category_id', 'tenant_asset_categories', 'CASCADE');
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_enum_check CHECK (
            dashboard_type IN ('overview','category','custom') AND visibility IN ('personal','organization')
            AND status IN ('active','archived'))");
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_shape_check CHECK (
            (dashboard_type = 'category') = (category_id IS NOT NULL)
            AND (dashboard_type = 'custom' OR visibility = 'organization')
            AND (visibility <> 'personal' OR created_by IS NOT NULL)
            AND (NOT is_default OR visibility = 'organization')
            AND (template_version IS NULL OR template_version >= 1))");
        // The Overview can never be deleted, softly or otherwise.
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_overview_kept_check CHECK (dashboard_type <> 'overview' OR deleted_at IS NULL)");
        DB::statement("CREATE TRIGGER dashboards_overview_no_delete BEFORE DELETE ON dashboards
            FOR EACH ROW WHEN (OLD.dashboard_type = 'overview') EXECUTE FUNCTION ate_forbid_modification()");
        DB::statement("CREATE TRIGGER dashboards_immutable BEFORE UPDATE ON dashboards
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('dashboard_type', 'category_id')");
        DB::statement("CREATE UNIQUE INDEX dashboards_overview_unique ON dashboards (organization_id) WHERE dashboard_type = 'overview'");
        DB::statement("CREATE UNIQUE INDEX dashboards_category_unique ON dashboards (category_id) WHERE dashboard_type = 'category' AND deleted_at IS NULL");
        DB::statement('CREATE UNIQUE INDEX dashboards_default_unique ON dashboards (organization_id) WHERE is_default AND deleted_at IS NULL');

        // The template is a system template or one of the dashboard's own organization.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_dashboard_template_owner() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE owner uuid;
            BEGIN
                IF NEW.template_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT t.organization_id INTO owner FROM dashboard_templates t WHERE t.id = NEW.template_id;
                IF NOT FOUND OR (owner IS NOT NULL AND owner <> NEW.organization_id) THEN
                    RAISE EXCEPTION 'A dashboard uses a system template or one of its own organization' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER dashboards_template_owner BEFORE INSERT OR UPDATE OF template_id ON dashboards
            FOR EACH ROW EXECUTE FUNCTION ate_dashboard_template_owner()');

        TenantSchema::enableRls('dashboards');
        // Personal dashboards: their owner only (not the organization's admins either).
        DB::statement('CREATE POLICY dashboards_personal ON dashboards AS RESTRICTIVE
            USING (visibility <> \'personal\' OR created_by = (SELECT ate_current_user()) OR (SELECT ate_platform_context()))
            WITH CHECK (visibility <> \'personal\' OR created_by = (SELECT ate_current_user()) OR (SELECT ate_platform_context()))');
        // Dashboards are soft-deleted: no hard deletes by the runtime role.
        DB::statement("GRANT SELECT, INSERT, UPDATE ON dashboards TO {$app}");
    }

    private function createDashboardWidgets(string $app): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('dashboard_id');
            $table->foreignUuid('widget_catalog_id')->constrained('widget_catalog');
            // NULL = the catalog name.
            $table->string('title', 150)->nullable();
            $table->jsonb('configuration')->default('{}');
            $table->jsonb('layout')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->index(['dashboard_id', 'sort_order']);
        });
        TenantSchema::tenantKey('dashboard_widgets');
        TenantSchema::tenantForeign('dashboard_widgets', 'dashboard_id', 'dashboards', 'CASCADE');
        DB::statement("ALTER TABLE dashboard_widgets ADD CONSTRAINT dashboard_widgets_json_check
            CHECK (jsonb_typeof(configuration) = 'object' AND jsonb_typeof(layout) = 'object')");

        TenantSchema::enableRls('dashboard_widgets');
        $this->parentDashboardRls('dashboard_widgets');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON dashboard_widgets TO {$app}");
    }

    private function createShares(string $app): void
    {
        Schema::create('dashboard_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('dashboard_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->string('permission', 10);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'role_id']);
        });
        TenantSchema::tenantKey('dashboard_shares');
        TenantSchema::tenantForeign('dashboard_shares', 'dashboard_id', 'dashboards', 'CASCADE');
        TenantSchema::memberForeign('dashboard_shares', 'user_id');
        // Deleting a role takes its shares with it.
        TenantSchema::tenantForeign('dashboard_shares', 'role_id', 'roles', 'CASCADE');
        TenantSchema::memberForeign('dashboard_shares', 'created_by');
        DB::statement('ALTER TABLE dashboard_shares ADD CONSTRAINT dashboard_shares_target_check CHECK (num_nonnulls(user_id, role_id) = 1)');
        DB::statement("ALTER TABLE dashboard_shares ADD CONSTRAINT dashboard_shares_permission_check CHECK (permission IN ('view','edit','manage'))");
        DB::statement('CREATE UNIQUE INDEX dashboard_shares_user_unique ON dashboard_shares (dashboard_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX dashboard_shares_role_unique ON dashboard_shares (dashboard_id, role_id) WHERE role_id IS NOT NULL');
        // Only organization dashboards are shared; a personal one stays its owner's.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_dashboard_share_target() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE kind text;
            BEGIN
                SELECT d.visibility INTO kind FROM dashboards d WHERE d.id = NEW.dashboard_id;
                IF NOT FOUND OR kind <> 'organization' THEN
                    RAISE EXCEPTION 'Only organization dashboards can be shared' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER dashboard_shares_target BEFORE INSERT OR UPDATE ON dashboard_shares
            FOR EACH ROW EXECUTE FUNCTION ate_dashboard_share_target()');

        TenantSchema::enableRls('dashboard_shares');
        $this->parentDashboardRls('dashboard_shares');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON dashboard_shares TO {$app}");
    }

    private function createPreferences(string $app): void
    {
        Schema::create('user_dashboard_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('user_id');
            $table->uuid('default_dashboard_id')->nullable();
            $table->jsonb('sidebar_preferences')->default('{}');
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
        });
        TenantSchema::memberForeign('user_dashboard_preferences', 'user_id');
        // Same organization only; a removed dashboard just clears the choice (the Overview applies).
        TenantSchema::tenantForeign('user_dashboard_preferences', 'default_dashboard_id', 'dashboards', 'SET NULL (default_dashboard_id)');
        DB::statement("ALTER TABLE user_dashboard_preferences ADD CONSTRAINT user_dashboard_preferences_json_check
            CHECK (jsonb_typeof(sidebar_preferences) = 'object')");

        TenantSchema::enableRls('user_dashboard_preferences');
        DB::statement('CREATE POLICY user_dashboard_preferences_own ON user_dashboard_preferences AS RESTRICTIVE
            USING (user_id = (SELECT ate_current_user()) OR (SELECT ate_platform_context()))
            WITH CHECK (user_id = (SELECT ate_current_user()) OR (SELECT ate_platform_context()))');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON user_dashboard_preferences TO {$app}");
    }

    /**
     * Rows of a system owner (organization_id NULL) are readable by every organization and
     * written only in the system context; organization rows follow the tenant policy.
     */
    private function sharedReferenceRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $write = '(organization_id = (SELECT ate_current_org()) OR (SELECT ate_system_context()))';
        DB::statement("CREATE POLICY {$table}_select ON {$table} FOR SELECT
            USING (organization_id IS NULL OR organization_id = (SELECT ate_current_org()) OR (SELECT ate_platform_context()))");
        DB::statement("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK {$write}");
        DB::statement("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING {$write} WITH CHECK {$write}");
        DB::statement("CREATE POLICY {$table}_delete ON {$table} FOR DELETE USING {$write}");
    }

    /** Rows are visible and writable only together with their dashboard (its RLS applies in the subquery). */
    private function parentDashboardRls(string $table): void
    {
        $parent = "EXISTS (SELECT 1 FROM dashboards d WHERE d.id = {$table}.dashboard_id)";
        DB::statement("CREATE POLICY {$table}_dashboard ON {$table} AS RESTRICTIVE USING ({$parent}) WITH CHECK ({$parent})");
    }
};
