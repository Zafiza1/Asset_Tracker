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
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        // Tenant context for Row-Level Security; set per request/job by App\Domain\Shared\Tenancy\Tenancy.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_current_org() RETURNS uuid
            LANGUAGE sql STABLE AS $$ SELECT nullif(current_setting('app.organization_id', true), '')::uuid $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_platform_context() RETURNS boolean
            LANGUAGE sql STABLE AS $$ SELECT coalesce(current_setting('app.platform_context', true), '') = 'on' $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_forbid_modification() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only', TG_TABLE_NAME USING ERRCODE = 'P0001';
            END $$
        SQL);

        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('locale', 10)->default('id');
            $table->char('currency', 3)->default('IDR');
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->text('address')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('active','suspended','archived'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_code_format CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{1,29}$')");

        Schema::create('organization_settings', function (Blueprint $table) {
            $table->uuid('organization_id')->primary();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->string('asset_number_format', 60)->default('AST-{YYYY}-{SEQ:6}');
            $table->string('transaction_number_format', 60)->default('{TYPE}-{YYYY}-{SEQ:6}');
            $table->boolean('allow_self_approval_default')->default(false);
            $table->unsignedSmallInteger('max_upload_mb')->default(20);
            $table->timestampsTz();
        });
        TenantSchema::enableRls('organization_settings');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_type', 16);
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('name', 150);
            $table->string('password');
            $table->string('status', 16)->default('active');
            $table->boolean('must_change_password')->default(false);
            $table->string('employee_number', 50)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->uuid('home_branch_id')->nullable();
            $table->uuid('home_department_id')->nullable();
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement('ALTER TABLE users ADD COLUMN email citext NOT NULL');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (
            (user_type = 'tenant' AND organization_id IS NOT NULL) OR (user_type = 'platform' AND organization_id IS NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('invited','active','suspended','deactivated'))");
        TenantSchema::tenantKey('users');
        // One user = one company: the owning organization and account type never change.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_users_identity_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_type IS DISTINCT FROM OLD.user_type THEN
                    RAISE EXCEPTION 'users.organization_id and users.user_type are immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER users_identity_immutable BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION ate_users_identity_immutable()');
        DB::statement('CREATE TRIGGER users_no_delete BEFORE DELETE ON users
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('organizations');
        DB::statement('DROP FUNCTION IF EXISTS ate_users_identity_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS ate_forbid_modification()');
        DB::statement('DROP FUNCTION IF EXISTS ate_platform_context()');
        DB::statement('DROP FUNCTION IF EXISTS ate_current_org()');
    }
};
