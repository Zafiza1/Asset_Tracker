<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Custom attributes may move to another section (kelompok); only default attributes keep theirs.
 * is_default stays immutable on every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_immutable ON tenant_category_attributes');
        DB::statement("CREATE TRIGGER tenant_category_attributes_default_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('is_default')");
        DB::statement("CREATE TRIGGER tenant_category_attributes_default_section_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW WHEN (OLD.is_default) EXECUTE FUNCTION ate_immutable_columns('section')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_section_immutable ON tenant_category_attributes');
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_immutable ON tenant_category_attributes');
        DB::statement("CREATE TRIGGER tenant_category_attributes_default_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('is_default', 'section')");
    }
};
