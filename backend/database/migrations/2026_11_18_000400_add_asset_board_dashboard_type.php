<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Dashboard Aset" per member (F-06 §4.1, D-21): each user gets a personal copy of the
 * organization template mapped to their role, provisioned on first open.
 *
 *  - dashboard_type 'asset_board' joins the enum of dashboards and templates. It is always
 *    personal (the shape check below keeps visibility = 'personal' mandatory) and owned by
 *    exactly one member: the partial unique index makes the lazy provisioning idempotent.
 *  - dashboard_templates.role_id already exists (2026_11_18_000300); the type check just
 *    learns the new dashboard type.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE dashboard_templates DROP CONSTRAINT dashboard_templates_type_check');
        DB::statement("ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_type_check CHECK (
            dashboard_type IN ('overview','category','custom','asset_board')
            AND (category_type_key IS NULL OR dashboard_type = 'category'))");

        DB::statement('ALTER TABLE dashboards DROP CONSTRAINT dashboards_enum_check');
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_enum_check CHECK (
            dashboard_type IN ('overview','category','custom','asset_board')
            AND visibility IN ('personal','organization')
            AND status IN ('active','archived'))");
        DB::statement('ALTER TABLE dashboards DROP CONSTRAINT dashboards_shape_check');
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_shape_check CHECK (
            (dashboard_type = 'category') = (category_id IS NOT NULL)
            AND (dashboard_type IN ('custom','asset_board') OR visibility = 'organization')
            AND (visibility <> 'personal' OR created_by IS NOT NULL)
            AND (NOT is_default OR visibility = 'organization')
            AND (template_version IS NULL OR template_version >= 1))");
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_asset_board_check CHECK (
            dashboard_type <> 'asset_board' OR (visibility = 'personal' AND created_by IS NOT NULL))");

        DB::statement("CREATE UNIQUE INDEX dashboards_asset_board_unique ON dashboards (organization_id, created_by)
            WHERE dashboard_type = 'asset_board' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP INDEX IF EXISTS dashboards_asset_board_unique');
        DB::statement('ALTER TABLE dashboards DROP CONSTRAINT IF EXISTS dashboards_asset_board_check');

        DB::statement('ALTER TABLE dashboards DROP CONSTRAINT dashboards_shape_check');
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_shape_check CHECK (
            (dashboard_type = 'category') = (category_id IS NOT NULL)
            AND (dashboard_type = 'custom' OR visibility = 'organization')
            AND (visibility <> 'personal' OR created_by IS NOT NULL)
            AND (NOT is_default OR visibility = 'organization')
            AND (template_version IS NULL OR template_version >= 1))");
        DB::statement('ALTER TABLE dashboards DROP CONSTRAINT dashboards_enum_check');
        DB::statement("ALTER TABLE dashboards ADD CONSTRAINT dashboards_enum_check CHECK (
            dashboard_type IN ('overview','category','custom')
            AND visibility IN ('personal','organization')
            AND status IN ('active','archived'))");

        DB::statement('ALTER TABLE dashboard_templates DROP CONSTRAINT dashboard_templates_type_check');
        DB::statement("ALTER TABLE dashboard_templates ADD CONSTRAINT dashboard_templates_type_check CHECK (
            dashboard_type IN ('overview','category','custom')
            AND (category_type_key IS NULL OR dashboard_type = 'category'))");
    }
};
