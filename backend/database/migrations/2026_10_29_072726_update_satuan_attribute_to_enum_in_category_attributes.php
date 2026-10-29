<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        // Disable the immutable trigger temporarily using raw SQL
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_immutable ON tenant_category_attributes');

        // Update ALL 'Satuan' attributes to ENUM with new options
        $unitOptions = json_encode([
            ['value' => 'PCS', 'label' => 'PCS'],
            ['value' => 'SET', 'label' => 'SET'],
            ['value' => 'MTR', 'label' => 'MTR'],
            ['value' => 'LTR', 'label' => 'LTR'],
            ['value' => 'RIM', 'label' => 'RIM'],
            ['value' => 'BK', 'label' => 'BK'],
            ['value' => 'KG', 'label' => 'KG'],
            ['value' => 'BOX', 'label' => 'BOX'],
            ['value' => 'CMS', 'label' => 'CMS'],
            ['value' => 'M2', 'label' => 'M2'],
            ['value' => 'JRG', 'label' => 'JRG'],
            ['value' => 'M3', 'label' => 'M3'],
            ['value' => 'GLN', 'label' => 'GLN'],
            ['value' => 'LBR', 'label' => 'LBR'],
            ['value' => 'ROLL', 'label' => 'ROLL'],
            ['value' => 'PCK', 'label' => 'PCK'],
            ['value' => 'SCK', 'label' => 'SCK'],
        ]);

        DB::statement("
            UPDATE tenant_category_attributes
            SET data_type = 'enum',
                options = '{$unitOptions}'
            WHERE label = 'Satuan'
        ");

        // Re-enable the immutable trigger
        DB::statement("CREATE TRIGGER tenant_category_attributes_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('category_id', 'key', 'data_type')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        // Disable the immutable trigger temporarily
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_immutable ON tenant_category_attributes');

        // Revert 'Satuan' attributes back to STRING
        DB::statement("
            UPDATE tenant_category_attributes
            SET data_type = 'string',
                options = NULL
            WHERE label = 'Satuan'
        ");

        // Re-enable the immutable trigger
        DB::statement("CREATE TRIGGER tenant_category_attributes_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('category_id', 'key', 'data_type')");
    }
};
