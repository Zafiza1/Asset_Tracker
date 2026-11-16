<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modules are picked per asset category instead of per asset (Menu Modul lists categories, not
 * every asset): an asset uses a module when its category or one of the category's ancestors has
 * it. A category whose assets used a module (asset_modules) gets that module; asset_modules goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('category_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->uuid('category_id');
            $table->string('module', 40);
            $table->timestampsTz();
            $table->unique(['category_id', 'module']);
            $table->index(['organization_id', 'module']);
        });
        TenantSchema::tenantForeign('category_modules', 'category_id', 'tenant_asset_categories', 'CASCADE');
        TenantSchema::enableRls('category_modules');

        $app = config('database.app_role');
        DB::statement("GRANT SELECT, INSERT, DELETE ON category_modules TO {$app}");

        if (Schema::hasTable('asset_modules')) {
            DB::insert('INSERT INTO category_modules (id, organization_id, category_id, module, created_at, updated_at)
                SELECT gen_random_uuid(), a.organization_id, a.category_id, m.module, now(), now()
                FROM asset_modules m JOIN assets a ON a.id = m.asset_id
                GROUP BY a.organization_id, a.category_id, m.module
                ON CONFLICT (category_id, module) DO NOTHING');
            Schema::drop('asset_modules');
        }
    }

    public function down(): void
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

        // Every asset of a category with the module (subcategories included) gets it back.
        DB::insert('INSERT INTO asset_modules (id, organization_id, asset_id, module, created_at, updated_at)
            SELECT gen_random_uuid(), a.organization_id, a.id, cm.module, now(), now()
            FROM category_modules cm
            JOIN tenant_asset_categories c ON c.id = cm.category_id
            JOIN tenant_asset_categories ac ON ac.organization_id = c.organization_id AND ac.path LIKE c.path || \'%\'
            JOIN assets a ON a.category_id = ac.id
            ON CONFLICT (asset_id, module) DO NOTHING');
        Schema::dropIfExists('category_modules');
    }
};
