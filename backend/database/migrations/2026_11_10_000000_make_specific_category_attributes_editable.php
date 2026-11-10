<?php

use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The suggested fields of the standard categories ("atribut khusus", e.g. Nomor Polisi) become
 * ordinary fields the organization may edit, move to another section or remove. Only the general
 * fields every top-level category has (CategoryAttributeService::DEFAULT_ATTRIBUTES) stay default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $general = collect(CategoryAttributeService::DEFAULT_ATTRIBUTES)->flatten(1)
            ->map(fn (array $a) => mb_strtolower($a['label']))->all();

        // is_default is otherwise immutable (DB trigger).
        DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_immutable ON tenant_category_attributes');
        DB::table('tenant_category_attributes')
            ->where('is_default', true)
            ->whereNotIn(DB::raw('lower(label)'), $general)
            ->update(['is_default' => false, 'updated_at' => now()]);
        DB::statement("CREATE TRIGGER tenant_category_attributes_default_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('is_default')");
    }

    public function down(): void
    {
        // Fields the organizations may have edited since stay ordinary fields.
    }
};
