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
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('scope', 16);
            $table->string('group', 40);
            $table->string('description', 255);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check CHECK (scope IN ('platform','tenant'))");

        // ---- Platform roles: only for user_type = 'platform' ----
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestampsTz();
        });
        Schema::create('platform_role_permissions', function (Blueprint $table) {
            $table->foreignUuid('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['platform_role_id', 'permission_id']);
        });
        Schema::create('platform_user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('platform_role_id')->constrained('platform_roles');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'platform_role_id']);
        });

        // ---- Tenant roles ----
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('template_code', 50)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('roles');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('role_id');
            $table->foreignUuid('permission_id')->constrained('permissions');
            $table->primary(['role_id', 'permission_id']);
        });
        TenantSchema::tenantForeign('role_permissions', 'role_id', 'roles', 'CASCADE');

        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
        TenantSchema::tenantForeign('user_roles', 'user_id', 'users');
        TenantSchema::tenantForeign('user_roles', 'role_id', 'roles');

        Schema::create('user_data_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('scope_type', 16);
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE user_data_scopes ADD CONSTRAINT user_data_scopes_ref_check CHECK (
            (scope_type = 'organization' AND branch_id IS NULL AND department_id IS NULL AND location_id IS NULL) OR
            (scope_type = 'branch' AND branch_id IS NOT NULL AND department_id IS NULL AND location_id IS NULL) OR
            (scope_type = 'department' AND department_id IS NOT NULL AND branch_id IS NULL AND location_id IS NULL) OR
            (scope_type = 'location' AND location_id IS NOT NULL AND branch_id IS NULL AND department_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX user_data_scopes_unique ON user_data_scopes
            (user_id, scope_type, coalesce(branch_id, department_id, location_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        TenantSchema::tenantForeign('user_data_scopes', 'user_id', 'users', 'CASCADE');
        TenantSchema::tenantForeign('user_data_scopes', 'branch_id', 'branches');
        TenantSchema::tenantForeign('user_data_scopes', 'department_id', 'departments');
        TenantSchema::tenantForeign('user_data_scopes', 'location_id', 'locations');

        foreach (['roles', 'role_permissions', 'user_roles', 'user_data_scopes'] as $t) {
            TenantSchema::enableRls($t);
        }

        // Platform permissions can never be granted to tenant roles and vice versa;
        // platform roles can only be held by platform users.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_check_permission_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE expected text := TG_ARGV[0];
            BEGIN
                IF (SELECT scope FROM permissions WHERE id = NEW.permission_id) IS DISTINCT FROM expected THEN
                    RAISE EXCEPTION 'Permission scope mismatch: % role requires % permission', expected, expected USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement("CREATE TRIGGER role_permissions_scope BEFORE INSERT OR UPDATE ON role_permissions
            FOR EACH ROW EXECUTE FUNCTION ate_check_permission_scope('tenant')");
        DB::statement("CREATE TRIGGER platform_role_permissions_scope BEFORE INSERT OR UPDATE ON platform_role_permissions
            FOR EACH ROW EXECUTE FUNCTION ate_check_permission_scope('platform')");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_check_platform_user() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT user_type FROM users WHERE id = NEW.user_id) IS DISTINCT FROM 'platform' THEN
                    RAISE EXCEPTION 'Platform roles can only be assigned to platform users' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER platform_user_roles_type BEFORE INSERT OR UPDATE ON platform_user_roles
            FOR EACH ROW EXECUTE FUNCTION ate_check_platform_user()');
    }

    public function down(): void
    {
        foreach (['user_data_scopes', 'user_roles', 'role_permissions', 'roles', 'platform_user_roles',
            'platform_role_permissions', 'platform_roles', 'permissions'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::statement('DROP FUNCTION IF EXISTS ate_check_permission_scope()');
        DB::statement('DROP FUNCTION IF EXISTS ate_check_platform_user()');
    }
};
