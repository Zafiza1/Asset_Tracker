<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::table('tenant_category_attributes', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
            $table->string('section', 50)->nullable();
            $table->index(['organization_id', 'section']);
        });

        DB::statement("ALTER TABLE tenant_category_attributes ADD CONSTRAINT tenant_category_attributes_section_check CHECK (section IS NULL OR section IN ('basic_information', 'location_ownership', 'status_condition'))");
        DB::statement("CREATE TRIGGER tenant_category_attributes_default_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('is_default', 'section')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_immutable ON tenant_category_attributes');
        DB::statement('ALTER TABLE tenant_category_attributes DROP CONSTRAINT IF EXISTS tenant_category_attributes_section_check');

        Schema::table('tenant_category_attributes', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'section']);
            $table->dropColumn(['is_default', 'section']);
        });
    }
};
