<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional modules an organization switched on (OrganizationModules::CATALOG); a module
 * without a row is off. Starts empty: every organization begins with every module off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('organization_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('module', 40);
            $table->timestampsTz();
            $table->unique(['organization_id', 'module']);
        });
        TenantSchema::enableRls('organization_modules');

        $app = config('database.app_role');
        // UPDATE only for SELECT ... FOR UPDATE, which serializes concurrent switches.
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON organization_modules TO {$app}");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('organization_modules');
    }
};
