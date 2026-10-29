<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One account, many organizations: a tenant user can belong to (and create) several
 * organizations and logs in with the same credentials to each of them.
 *
 * - organization_members holds the per-organization side of an account (status in that
 *   organization, employee number, job title, home department).
 * - Every composite foreign key that pointed to users (organization_id, id) now points to
 *   organization_members (organization_id, user_id), so tenant data can still only
 *   reference people who belong to the same organization.
 * - users keeps only the global identity (name, email, password, account status).
 */
return new class extends Migration
{
    /** Composite FKs to re-point: table => [column => ON DELETE]. */
    private const USER_REFERENCES = [
        'user_roles' => ['user_id' => 'RESTRICT'],
        'user_data_scopes' => ['user_id' => 'CASCADE'],
        'tenant_asset_definitions' => ['created_by' => 'RESTRICT'],
        'tenant_asset_definition_versions' => ['created_by' => 'RESTRICT', 'released_by' => 'RESTRICT', 'deprecated_by' => 'RESTRICT'],
        'assets' => ['archived_by' => 'RESTRICT', 'created_by' => 'RESTRICT', 'updated_by' => 'RESTRICT'],
        'asset_transactions' => ['created_by' => 'RESTRICT'],
    ];

    public function up(): void
    {
        $this->platformContext();

        Schema::create('organization_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('status', 16)->default('active');
            $table->string('employee_number', 50)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->uuid('home_department_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'employee_number']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE organization_members ADD CONSTRAINT organization_members_status_check CHECK (status IN ('invited','active','deactivated'))");
        TenantSchema::tenantForeign('organization_members', 'home_department_id', 'departments');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_check_member_is_tenant_user() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT user_type FROM users WHERE id = NEW.user_id) IS DISTINCT FROM 'tenant' THEN
                    RAISE EXCEPTION 'Only tenant users can be organization members' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id) THEN
                    RAISE EXCEPTION 'organization_members.organization_id and user_id are immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER organization_members_identity BEFORE INSERT OR UPDATE ON organization_members
            FOR EACH ROW EXECUTE FUNCTION ate_check_member_is_tenant_user()');
        DB::statement('CREATE TRIGGER organization_members_no_delete BEFORE DELETE ON organization_members
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()');

        DB::statement(<<<'SQL'
            INSERT INTO organization_members (id, organization_id, user_id, status, employee_number, job_title, home_department_id, created_by, created_at, updated_at)
            SELECT gen_random_uuid(), organization_id, id, status, employee_number, job_title, home_department_id, created_by, created_at, updated_at
            FROM users WHERE user_type = 'tenant'
        SQL);
        TenantSchema::enableRls('organization_members');
        // Membership status now carries the per-organization state; the account itself stays usable.
        DB::statement("UPDATE users SET status = 'active' WHERE user_type = 'tenant' AND status <> 'active'");

        $this->repointUserReferences('organization_members', 'user_id');
        // The same account now holds scopes in several organizations.
        $this->dataScopeUniqueIndex('organization_id, user_id');

        DB::statement('DROP TRIGGER users_identity_immutable ON users');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_type_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_home_department_id_tfk');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_org_id_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'employee_number']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['employee_number', 'job_title', 'home_department_id']);
            $table->foreignUuid('last_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (user_type IN ('tenant','platform'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_users_identity_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.user_type IS DISTINCT FROM OLD.user_type THEN
                    RAISE EXCEPTION 'users.user_type is immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER users_identity_immutable BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION ate_users_identity_immutable()');
    }

    /**
     * Restores the single-organization structure. A user who belongs to several
     * organizations keeps only the oldest membership; this fails if other memberships
     * are referenced by tenant data.
     */
    public function down(): void
    {
        $this->platformContext();
        DB::statement('DROP TRIGGER users_identity_immutable ON users');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_type_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_organization_id');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('employee_number', 50)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->uuid('home_department_id')->nullable();
            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement(<<<'SQL'
            UPDATE users u SET organization_id = m.organization_id, employee_number = m.employee_number,
                job_title = m.job_title, home_department_id = m.home_department_id,
                status = CASE WHEN m.status = 'active' THEN u.status ELSE m.status END
            FROM (SELECT DISTINCT ON (user_id) * FROM organization_members ORDER BY user_id, created_at) m
            WHERE m.user_id = u.id
        SQL);
        TenantSchema::tenantKey('users');
        TenantSchema::tenantForeign('users', 'home_department_id', 'departments');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (
            (user_type = 'tenant' AND organization_id IS NOT NULL) OR (user_type = 'platform' AND organization_id IS NULL))");

        $this->repointUserReferences('users', 'id');
        $this->dataScopeUniqueIndex('user_id');

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

        Schema::drop('organization_members');
        DB::statement('DROP FUNCTION IF EXISTS ate_check_member_is_tenant_user()');
    }

    /**
     * Forced RLS also applies to the owner role: without this, the data copies and the
     * validation of re-created foreign keys would only see rows of "no organization".
     * Transaction-local, so it ends with the migration.
     */
    private function platformContext(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
    }

    private function dataScopeUniqueIndex(string $leadingColumns): void
    {
        DB::statement('DROP INDEX user_data_scopes_unique');
        DB::statement("CREATE UNIQUE INDEX user_data_scopes_unique ON user_data_scopes
            ({$leadingColumns}, scope_type, coalesce(department_id, location_id, '00000000-0000-0000-0000-000000000000'::uuid))");
    }

    private function repointUserReferences(string $parent, string $parentColumn): void
    {
        foreach (self::USER_REFERENCES as $table => $columns) {
            foreach ($columns as $column => $onDelete) {
                $name = substr("{$table}_{$column}_tfk", 0, 63);
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}
                    FOREIGN KEY (organization_id, {$column}) REFERENCES {$parent} (organization_id, {$parentColumn}) ON DELETE {$onDelete}");
            }
        }
    }
};
