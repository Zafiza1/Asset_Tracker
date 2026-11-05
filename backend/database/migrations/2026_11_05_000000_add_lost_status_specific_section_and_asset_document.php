<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schema for the standard asset categories: the "lost" asset status, the section of category
 * specific default fields, and one document per asset.
 */
return new class extends Migration
{
    private const OLD_STATUSES = "'available','in_use','on_loan','under_maintenance','disposed'";

    private const NEW_STATUSES = "'available','in_use','on_loan','under_maintenance','lost','disposed'";

    public function up(): void
    {
        $this->statuses(self::NEW_STATUSES);
        $this->sections("'basic_information', 'location_ownership', 'status_condition', 'specific_attributes'");
        DB::statement('ALTER TABLE assets ADD COLUMN document_path varchar(500), ADD COLUMN document_name varchar(255)');
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE assets DROP COLUMN document_path, DROP COLUMN document_name');
        $this->sections("'basic_information', 'location_ownership', 'status_condition'");
        $this->statuses(self::OLD_STATUSES);
    }

    private function statuses(string $statuses): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_status_check');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ({$statuses})) NOT VALID");
        foreach (['from_status', 'to_status'] as $column) {
            DB::statement("ALTER TABLE asset_transactions DROP CONSTRAINT asset_transactions_{$column}_check");
            DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_{$column}_check CHECK ({$column} IS NULL OR {$column} IN ({$statuses})) NOT VALID");
        }
    }

    private function sections(string $sections): void
    {
        DB::statement('ALTER TABLE tenant_category_attributes DROP CONSTRAINT tenant_category_attributes_section_check');
        DB::statement("ALTER TABLE tenant_category_attributes ADD CONSTRAINT tenant_category_attributes_section_check CHECK (section IS NULL OR section IN ({$sections})) NOT VALID");
    }
};
