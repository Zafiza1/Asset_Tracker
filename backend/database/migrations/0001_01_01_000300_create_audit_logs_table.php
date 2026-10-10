<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = platform-level event (platform admin action, failed login for unknown email).
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('actor_type', 16);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['organization_id', 'action']);
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user','system','anonymous'))");

        // Append-only for the application: trigger blocks UPDATE/DELETE and the runtime
        // role only keeps SELECT + INSERT. (A database superuser can still alter rows; see docs.)
        TenantSchema::forbidUpdateDelete('audit_logs');
        $appRole = config('database.app_role', 'ate_app');
        if (DB::selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$appRole])) {
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM {$appRole}");
        }
        TenantSchema::enableRls('audit_logs', allowPlatformRowsInsert: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
