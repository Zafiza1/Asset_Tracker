<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which optional modules (OrganizationModule::CATALOG) each asset uses. Replaces the default
 * yes/no "Barcode" category field: assets whose field was Ya get the barcode module, the field
 * is archived (its key stays reserved) and its values are removed from the assets.
 * Assets are picked in Menu Modul; the detail page lists the asset's modules under Informasi Dasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('asset_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('asset_id');
            $table->string('module', 40);
            $table->timestampsTz();
            $table->unique(['asset_id', 'module']);
            $table->index(['organization_id', 'module']);
        });
        TenantSchema::tenantForeign('asset_modules', 'asset_id', 'assets', 'CASCADE');
        TenantSchema::enableRls('asset_modules');

        $app = config('database.app_role');
        DB::statement("GRANT SELECT, INSERT, DELETE ON asset_modules TO {$app}");

        $fields = DB::table('tenant_category_attributes')->where('is_default', true)->where('label', 'Barcode')
            ->where('data_type', 'boolean')->get(['id', 'organization_id', 'key']);

        foreach ($fields as $field) {
            DB::insert("INSERT INTO asset_modules (id, organization_id, asset_id, module, created_at, updated_at)
                SELECT gen_random_uuid(), organization_id, id, 'barcode', now(), now() FROM assets
                WHERE organization_id = ? AND attribute_values -> ? = 'true'::jsonb
                ON CONFLICT (asset_id, module) DO NOTHING", [$field->organization_id, $field->key]);
            DB::update('UPDATE assets SET attribute_values = attribute_values - ? WHERE organization_id = ? AND jsonb_exists(attribute_values, ?)',
                [$field->key, $field->organization_id, $field->key]);
        }

        DB::table('tenant_category_attributes')->whereIn('id', $fields->pluck('id'))
            ->update(['status' => 'archived', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('asset_modules');
    }
};
