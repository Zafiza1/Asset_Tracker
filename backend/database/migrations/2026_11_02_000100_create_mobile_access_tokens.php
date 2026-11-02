<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens of the mobile scanner app (Sanctum personal access tokens). Each token is
 * bound to one organization at login, since a stateless client has no session to keep the
 * active organization in. The guard reads and touches tokens before any tenant context
 * exists, so every row is reachable only in the system context (MobileAccessToken wraps
 * its queries); a tenant context, and SQL injection in one, sees no tokens at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('mobile_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE mobile_access_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE mobile_access_tokens FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY mobile_access_tokens_system ON mobile_access_tokens
            USING ((SELECT ate_system_context())) WITH CHECK ((SELECT ate_system_context()))');

        $app = config('database.app_role');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON mobile_access_tokens TO {$app}");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('mobile_access_tokens');
    }
};
