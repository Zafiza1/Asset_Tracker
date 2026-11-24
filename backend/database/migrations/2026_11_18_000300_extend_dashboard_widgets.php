<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard widgets phase 2 (docs/factory/features/F-06 §4.3, §5; F-07g):
 *
 *  - widget_catalog.required_permission: the data permission a member must hold for the
 *    widget to be offered in the catalog and for its data endpoint to answer. NULL = any
 *    dashboard viewer (reference widgets like notes and shortcuts).
 *  - widget_catalog.component_type gains 'note' and 'shortcuts' for the free-text and
 *    quick-link widgets of §4.3.
 *  - dashboard_templates.role_id: the organization role a template is the default for
 *    (D-21). Only organization templates map to roles — system templates are shared by
 *    every organization, so they cannot name a tenant role. The composite FK keeps the
 *    role inside the template's own organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE widget_catalog ADD COLUMN required_permission varchar(60) NULL');
        DB::statement('ALTER TABLE widget_catalog DROP CONSTRAINT widget_catalog_component_type_check');
        DB::statement("ALTER TABLE widget_catalog ADD CONSTRAINT widget_catalog_component_type_check
            CHECK (component_type IN ('statistic','chart','table','activity','status','reminder','location','note','shortcuts'))");

        DB::statement('ALTER TABLE dashboard_templates ADD COLUMN role_id uuid NULL');
        DB::statement("ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_role_check
            CHECK (role_id IS NULL OR scope = 'organization')");
        DB::statement('ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_role_id_tfk
            FOREIGN KEY (organization_id, role_id) REFERENCES roles (organization_id, id) ON DELETE SET NULL');
        // At most one active template per role (the default the member's copy comes from).
        DB::statement('CREATE UNIQUE INDEX dashboard_templates_role ON dashboard_templates
            (organization_id, role_id) WHERE role_id IS NOT NULL AND is_active');
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP INDEX IF EXISTS dashboard_templates_role');
        DB::statement('ALTER TABLE dashboard_templates DROP CONSTRAINT IF EXISTS dashboard_templates_role_id_tfk');
        DB::statement('ALTER TABLE dashboard_templates DROP CONSTRAINT IF EXISTS dashboard_templates_role_check');
        DB::statement('ALTER TABLE dashboard_templates DROP COLUMN IF EXISTS role_id');

        DB::statement('ALTER TABLE widget_catalog DROP CONSTRAINT widget_catalog_component_type_check');
        DB::statement("ALTER TABLE widget_catalog ADD CONSTRAINT widget_catalog_component_type_check
            CHECK (component_type IN ('statistic','chart','table','activity','status','reminder','location'))");
        DB::statement('ALTER TABLE widget_catalog DROP COLUMN IF EXISTS required_permission');
    }
};
