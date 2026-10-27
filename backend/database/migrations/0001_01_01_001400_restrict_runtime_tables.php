<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-07: the runtime role only reaches the global tables it needs, in the context that needs
 * them. Before this, 20 tables without RLS gave it full DML: SQL injection in a tenant context
 * could read or rewrite any account, grant platform roles, or create organizations.
 *
 *  - users: visible and updatable for the platform/system context, the account itself, and
 *    members of the current organization. Inserts only in the platform/system context. In a
 *    tenant context, another account can only be changed while it belongs to no other
 *    organization (as UserManagement::assertExclusive), so one organization cannot take over
 *    an account that also opens another one.
 *  - organizations: platform/system, or the current organization. Inserts only in the
 *    platform/system context; code and status never change in a tenant context; no deletes.
 *  - platform_user_roles: readable by the platform/system context and the account itself;
 *    writable only in the system context. permissions, platform_roles and
 *    platform_role_permissions are reference data, writable only in the system context.
 *  - migrations and password_reset_tokens: no access (SEC-06 has no reset flow yet).
 *  - New tables no longer get DML by default; migrations grant what they need.
 *
 * Sessions, cache and queue tables move to their own roles in migration 001500.
 */
return new class extends Migration
{
    private const SYSTEM_ONLY_TABLES = ['permissions', 'platform_roles', 'platform_role_permissions'];

    public function up(): void
    {
        $app = config('database.app_role');
        $ctx = config('database.context_role');
        $owner = DB::selectOne('SELECT current_user AS name')->name;
        $ownerLiteral = DB::getPdo()->quote($owner);

        // Like ate_platform_context() (migration 001300), for the system mode only.
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION public.ate_system_context() RETURNS boolean
            LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp
            AS \$\$ BEGIN
                IF session_user = {$ownerLiteral} THEN
                    RETURN coalesce(pg_catalog.current_setting('app.platform_context', true) = 'on', false);
                END IF;
                RETURN coalesce((ate_ctx.verified_context()).mode = 'system', false);
            END \$\$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION public.ate_system_context() FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION public.ate_system_context() TO {$app}");

        // Integrity triggers must see rows that RLS now hides from the current context: the
        // memberships of other organizations (users guard), and the type of any account
        // (membership, platform role and platform invitation checks).
        DB::statement("SET LOCAL ROLE {$ctx}");
        DB::statement(<<<'SQL'
            CREATE FUNCTION ate_ctx.member_elsewhere(account uuid, organization uuid) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp
            AS $$ SELECT EXISTS (SELECT 1 FROM public.organization_members m
                WHERE m.user_id = account AND m.organization_id IS DISTINCT FROM organization) $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE FUNCTION ate_ctx.user_type(account uuid) RETURNS text
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp
            AS $$ SELECT u.user_type::text FROM public.users u WHERE u.id = account $$
        SQL);
        foreach (['member_elsewhere(uuid, uuid)', 'user_type(uuid)'] as $fn) {
            DB::statement("REVOKE ALL ON FUNCTION ate_ctx.{$fn} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION ate_ctx.{$fn} TO {$app}, {$owner}");
        }
        DB::statement('RESET ROLE');
        $this->defineTypeChecks('ate_ctx.user_type(NEW.user_id)', 'ate_ctx.user_type(NEW.invited_by_platform_user_id)');

        $this->restrictUsers($app);
        $this->restrictOrganizations($app);

        DB::statement('ALTER TABLE platform_user_roles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_user_roles FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY platform_user_roles_select ON platform_user_roles FOR SELECT
            USING ((SELECT ate_platform_context()) OR user_id = (SELECT ate_current_user()))');
        DB::statement('CREATE POLICY platform_user_roles_insert ON platform_user_roles FOR INSERT WITH CHECK ((SELECT ate_system_context()))');
        DB::statement('CREATE POLICY platform_user_roles_update ON platform_user_roles FOR UPDATE
            USING ((SELECT ate_system_context())) WITH CHECK ((SELECT ate_system_context()))');
        DB::statement('CREATE POLICY platform_user_roles_delete ON platform_user_roles FOR DELETE USING ((SELECT ate_system_context()))');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_require_system_context() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT ate_system_context() THEN
                    RAISE EXCEPTION 'Table % is writable only by the system', TG_TABLE_NAME USING ERRCODE = '42501';
                END IF;
                RETURN NULL;
            END $$
        SQL);
        foreach (self::SYSTEM_ONLY_TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_system_only BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION ate_require_system_context()");
        }

        DB::statement("REVOKE ALL ON migrations, password_reset_tokens FROM {$app}");
        DB::statement("REVOKE ALL ON SEQUENCE migrations_id_seq FROM {$app}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$app}");
    }

    public function down(): void
    {
        $app = config('database.app_role');
        $ctx = config('database.context_role');

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$app}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON migrations, password_reset_tokens TO {$app}");
        DB::statement("GRANT USAGE, SELECT, UPDATE ON SEQUENCE migrations_id_seq TO {$app}");

        foreach (self::SYSTEM_ONLY_TABLES as $table) {
            DB::statement("DROP TRIGGER {$table}_system_only ON {$table}");
        }
        DB::statement('DROP FUNCTION public.ate_require_system_context()');

        foreach (['platform_user_roles', 'organizations', 'users'] as $table) {
            foreach (DB::select('SELECT policyname FROM pg_policies WHERE schemaname = ? AND tablename = ?', ['public', $table]) as $policy) {
                DB::statement("DROP POLICY {$policy->policyname} ON {$table}");
            }
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
        DB::statement('DROP TRIGGER organizations_tenant_guard ON organizations');
        DB::statement('DROP FUNCTION public.ate_organizations_tenant_guard()');
        DB::statement('DROP TRIGGER users_tenant_guard ON users');
        DB::statement('DROP FUNCTION public.ate_users_tenant_guard()');
        DB::statement("GRANT DELETE ON organizations, users TO {$app}");

        $this->defineTypeChecks('(SELECT user_type FROM users WHERE id = NEW.user_id)', '(SELECT user_type FROM users WHERE id = NEW.invited_by_platform_user_id)');
        DB::statement("SET LOCAL ROLE {$ctx}");
        DB::statement('DROP FUNCTION ate_ctx.member_elsewhere(uuid, uuid)');
        DB::statement('DROP FUNCTION ate_ctx.user_type(uuid)');
        DB::statement('RESET ROLE');
        DB::statement('DROP FUNCTION public.ate_system_context()');
    }

    /**
     * The account-type checks of migrations 000200, 000700 and 001200 (same names and bodies),
     * reading the type through $userType / $inviterType.
     */
    private function defineTypeChecks(string $userType, string $inviterType): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION ate_check_platform_user() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
                IF {$userType} IS DISTINCT FROM 'platform' THEN
                    RAISE EXCEPTION 'Platform roles can only be assigned to platform users' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION ate_check_member_is_tenant_user() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
                IF {$userType} IS DISTINCT FROM 'tenant' THEN
                    RAISE EXCEPTION 'Only tenant users can be organization members' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id) THEN
                    RAISE EXCEPTION 'organization_members.organization_id and user_id are immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION ate_invitation_platform_inviter() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.invited_by_platform_user_id IS NOT NULL
                    AND {$inviterType} IS DISTINCT FROM 'platform' THEN
                    RAISE EXCEPTION 'Only platform accounts can issue platform invitations' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END \$\$
        SQL);
    }

    private function restrictUsers(string $app): void
    {
        $visible = <<<'SQL'
            (SELECT ate_platform_context()) OR id = (SELECT ate_current_user())
            OR EXISTS (SELECT 1 FROM organization_members m WHERE m.user_id = users.id AND m.organization_id = (SELECT ate_current_org()))
        SQL;

        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY users_select ON users FOR SELECT USING ({$visible})");
        DB::statement('CREATE POLICY users_insert ON users FOR INSERT WITH CHECK ((SELECT ate_platform_context()))');
        DB::statement("CREATE POLICY users_update ON users FOR UPDATE USING ({$visible}) WITH CHECK ({$visible})");
        // Accounts are never deleted: no privilege, no policy, and users_no_delete for the owner.
        DB::statement("REVOKE DELETE ON users FROM {$app}");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_users_tenant_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                -- Tenant contexts only: without a context the runtime role sees no accounts at all,
                -- and maintenance as superuser must not be blocked.
                IF ate_current_org() IS NOT NULL AND OLD.id IS DISTINCT FROM ate_current_user()
                    AND ate_ctx.member_elsewhere(OLD.id, ate_current_org()) THEN
                    RAISE EXCEPTION 'Account % also belongs to another organization', OLD.id USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER users_tenant_guard BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION ate_users_tenant_guard()');
    }

    private function restrictOrganizations(string $app): void
    {
        $visible = '(SELECT ate_platform_context()) OR id = (SELECT ate_current_org())';

        DB::statement('ALTER TABLE organizations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE organizations FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY organizations_select ON organizations FOR SELECT USING ({$visible})");
        DB::statement('CREATE POLICY organizations_insert ON organizations FOR INSERT WITH CHECK ((SELECT ate_platform_context()))');
        DB::statement("CREATE POLICY organizations_update ON organizations FOR UPDATE USING ({$visible}) WITH CHECK ({$visible})");
        DB::statement("REVOKE DELETE ON organizations FROM {$app}");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_organizations_tenant_guard() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF ate_current_org() IS NOT NULL AND (NEW.code IS DISTINCT FROM OLD.code OR NEW.status IS DISTINCT FROM OLD.status) THEN
                    RAISE EXCEPTION 'organizations.code and organizations.status cannot change in a tenant context' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER organizations_tenant_guard BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION ate_organizations_tenant_guard()');
    }
};
