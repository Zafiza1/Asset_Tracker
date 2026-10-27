<?php

namespace Tests\Feature\Security;

use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\PlatformContext;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-07: what each database role can reach (migrations 001300–001500). The inventory test is
 * the guardrail for new tables: a table is either under forced RLS or listed here with its
 * exact privileges and, if the runtime role can write it, a statement-level context guard.
 */
class DatabasePrivilegesTest extends TestCase
{
    private const APP = 'ate_app';

    /** Tables without RLS: privileges of [runtime, session, queue] role, and the write guard. */
    private const NON_RLS_TABLES = [
        'cache' => ['', 'SIUD', '', null],
        'cache_locks' => ['', 'SIUD', '', null],
        'sessions' => ['', 'SIUD', '', null],
        'jobs' => ['', '', 'SIUD', null],
        'job_batches' => ['', '', 'SIUD', null],
        'failed_jobs' => ['', '', 'SIUD', null],
        'migrations' => ['', '', '', null],
        'password_reset_tokens' => ['', '', '', null],
        'permissions' => ['SIUD', '', '', 'ate_require_system_context'],
        'platform_roles' => ['SIUD', '', '', 'ate_require_system_context'],
        'platform_role_permissions' => ['SIUD', '', '', 'ate_require_system_context'],
        'generic_asset_categories' => ['SIUD', '', '', 'ate_require_platform_context'],
        'generic_asset_definitions' => ['SIUD', '', '', 'ate_require_platform_context'],
        'generic_asset_definition_versions' => ['SIUD', '', '', 'ate_require_platform_context'],
        'generic_attribute_definitions' => ['SIUD', '', '', 'ate_require_platform_context'],
        'generic_transaction_types' => ['SIUD', '', '', 'ate_require_platform_context'],
        'units' => ['SIUD', '', '', 'ate_require_platform_context'],
    ];

    /** Global tables that moved under RLS, with the runtime role's privileges. */
    private const GLOBAL_RLS_TABLES = [
        'users' => 'SIU',
        'organizations' => 'SIU',
        'platform_user_roles' => 'SIUD',
    ];

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
    }

    public function test_every_table_is_under_forced_rls_or_has_exactly_the_listed_privileges(): void
    {
        $session = config('database.connections.pgsql_session.username');
        $queue = config('database.connections.pgsql_queue.username');
        $tables = DB::select(<<<'SQL'
            SELECT c.relname AS name, c.relrowsecurity AND c.relforcerowsecurity AS rls,
                   (SELECT string_agg(DISTINCT p.proname, ',') FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
                    WHERE t.tgrelid = c.oid AND NOT t.tgisinternal AND t.tgtype & 1 = 0 AND t.tgtype & 28 = 28
                      AND p.proname IN ('ate_require_platform_context', 'ate_require_system_context')) AS guard
            FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p') ORDER BY 1
        SQL);

        $actual = [];
        $expected = [];
        foreach ($tables as $table) {
            $privileges = [$this->privileges(self::APP, $table->name), $this->privileges($session, $table->name), $this->privileges($queue, $table->name)];
            if (! $table->rls) {
                $actual[$table->name] = [...$privileges, $table->guard];
                $expected[$table->name] = self::NON_RLS_TABLES[$table->name] ?? 'not listed: needs RLS, or an entry in NON_RLS_TABLES';

                continue;
            }
            // Under RLS: nothing for the session and queue roles, and full DML only for tenant tables.
            $actual[$table->name] = $privileges;
            $expected[$table->name] = [self::GLOBAL_RLS_TABLES[$table->name] ?? $privileges[0], '', ''];
        }

        $this->assertSame($expected, $actual);
        $this->assertSame([], array_diff(array_keys(self::NON_RLS_TABLES + self::GLOBAL_RLS_TABLES), array_keys($actual)), 'Listed tables that no longer exist');
    }

    public function test_new_tables_get_no_privileges_by_default(): void
    {
        $defaults = DB::select(<<<'SQL'
            SELECT pg_get_userbyid(d.defaclrole) AS owner, d.defaclobjtype AS type, d.defaclacl::text AS acl
            FROM pg_default_acl d WHERE d.defaclnamespace = 'public'::regnamespace OR d.defaclnamespace = 0
        SQL);

        foreach ($defaults as $default) {
            if ($default->type === 'r') {
                $this->assertStringNotContainsString(self::APP.'=', $default->acl, "Default table privileges of {$default->owner}");
            }
        }
    }

    public function test_database_roles_have_only_the_attributes_and_memberships_they_need(): void
    {
        $ctx = config('database.context_role');
        $roles = collect(DB::select(<<<'SQL'
            SELECT r.rolname AS name, r.rolcanlogin AS login, r.rolsuper OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication AS elevated,
                   r.rolbypassrls AS bypassrls,
                   coalesce((SELECT string_agg(g.rolname, ',' ORDER BY g.rolname) FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid WHERE m.member = r.oid), '') AS member_of
            FROM pg_roles r WHERE r.rolname = ANY (?)
        SQL, ['{'.implode(',', [self::APP, $ctx, 'ate_session', 'ate_queue']).'}']))->keyBy('name');

        $this->assertEquals((object) ['name' => $ctx, 'login' => false, 'elevated' => false, 'bypassrls' => true, 'member_of' => 'pg_read_all_stats'], $roles[$ctx]);
        foreach ([self::APP, 'ate_session', 'ate_queue'] as $name) {
            $this->assertEquals((object) ['name' => $name, 'login' => true, 'elevated' => false, 'bypassrls' => false, 'member_of' => ''], $roles[$name]);
        }
    }

    public function test_security_definer_functions_live_in_the_context_schema_with_a_fixed_search_path(): void
    {
        $functions = DB::select(<<<'SQL'
            SELECT n.nspname AS schema, p.proname AS name, pg_get_userbyid(p.proowner) AS owner, p.proconfig::text AS config
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.prosecdef AND n.nspname NOT IN ('pg_catalog', 'information_schema') ORDER BY 2
        SQL);

        $this->assertSame(['member_elsewhere', 'user_type', 'verified_context'], array_column($functions, 'name'));
        foreach ($functions as $function) {
            $this->assertSame(['ate_ctx', config('database.context_role'), '{"search_path=pg_catalog, pg_temp"}'],
                [$function->schema, $function->owner, $function->config], $function->name);
        }
    }

    public function test_runtime_role_cannot_reach_sessions_cache_queue_or_migrations(): void
    {
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'] as $table) {
            $this->expectQueryError('42501', fn () => DB::table($table)->count());
        }
    }

    public function test_session_and_queue_roles_reach_only_their_own_tables(): void
    {
        $session = DB::connection('pgsql_session');
        $queue = DB::connection('pgsql_queue');

        $this->assertIsInt($session->table('sessions')->count());
        $this->assertIsInt($queue->table('jobs')->count());
        foreach (['users', 'organization_members', 'jobs'] as $table) {
            $this->expectQueryError('42501', fn () => $session->table($table)->count(), $session);
        }
        foreach (['users', 'organization_members', 'sessions', 'cache'] as $table) {
            $this->expectQueryError('42501', fn () => $queue->table($table)->count(), $queue);
        }
    }

    public function test_tenant_context_sees_member_accounts_and_its_own_organization_only(): void
    {
        $this->asTenantA(function () {
            $this->assertSame([$this->adminA->id], DB::table('users')->pluck('id')->all());
            $this->assertSame([$this->orgA->id], DB::table('organizations')->pluck('id')->all());
            $this->assertSame(0, DB::table('users')->where('id', $this->adminB->id)->update(['name' => 'Taken over']));
            $this->assertSame(0, DB::table('organizations')->where('id', $this->orgB->id)->update(['name' => 'Taken over']));
        });
    }

    public function test_tenant_context_cannot_change_an_account_that_also_belongs_to_another_organization(): void
    {
        $exclusive = $this->createTenantUser($this->orgA);
        $shared = $this->createTenantUser($this->orgB);
        $this->addMembership($shared, $this->orgA, 'VIEWER');

        $this->asTenantA(function () use ($exclusive, $shared) {
            $this->assertSame(1, DB::table('users')->where('id', $exclusive->id)->update(['password' => Hash::make('x')]));
            $this->expectQueryError('42501', fn () => DB::table('users')->where('id', $shared->id)->update(['password' => Hash::make('x')]));
            $this->expectQueryError('42501', fn () => DB::table('users')->where('id', $shared->id)->update(['email' => 'attacker@example.test']));
            // The account itself may change its own row.
            $this->assertSame(1, DB::table('users')->where('id', $this->adminA->id)->update(['name' => 'Admin A']));
        });
    }

    public function test_tenant_context_cannot_create_accounts_or_organizations_or_change_organization_identity(): void
    {
        $this->asTenantA(function () {
            $this->expectQueryError('42501', fn () => DB::table('users')->insert([
                'id' => (string) Str::uuid7(), 'user_type' => 'tenant', 'name' => 'X', 'email' => 'x@example.test',
                'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->expectQueryError('42501', fn () => DB::table('organizations')->insert([
                'id' => (string) Str::uuid7(), 'code' => 'EVIL', 'name' => 'Evil', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->expectQueryError('42501', fn () => DB::table('organizations')->where('id', $this->orgA->id)->update(['code' => 'RENAMED']));
            $this->expectQueryError('42501', fn () => DB::table('organizations')->where('id', $this->orgA->id)->update(['status' => 'archived']));
            $this->expectQueryError('42501', fn () => DB::table('organizations')->where('id', $this->orgA->id)->delete());
            $this->assertSame(1, DB::table('organizations')->where('id', $this->orgA->id)->update(['name' => 'Alpha Baru']));
        });
    }

    public function test_platform_roles_and_permissions_are_written_only_in_the_system_context(): void
    {
        $platform = $this->createPlatformAdmin();
        $other = $this->createPlatformAdmin(withRole: false);
        $role = PlatformRole::query()->firstOrFail();
        $permission = DB::table('permissions')->where('scope', 'platform')->value('id');

        $this->tenancy()->runAsPlatform(new PlatformContext($platform), function () use ($other, $role, $permission) {
            $this->expectQueryError('42501', fn () => DB::table('platform_user_roles')->insert(['user_id' => $other->id, 'platform_role_id' => $role->id]));
            $this->expectQueryError('42501', fn () => DB::table('platform_role_permissions')->where('platform_role_id', $role->id)->delete());
            $this->expectQueryError('42501', fn () => DB::table('permissions')->where('id', $permission)->update(['description' => 'x']));
            $this->expectQueryError('42501', fn () => DB::table('platform_roles')->where('id', $role->id)->update(['name' => 'x']));
        });

        $this->asTenantA(fn () => $this->assertSame(0, DB::table('platform_user_roles')->count()));
        $this->tenancy()->runAsPlatform(new PlatformContext($platform), fn () => $this->assertSame(1, DB::table('platform_user_roles')->where('user_id', $platform->id)->count()));
    }

    private function privileges(string $role, string $table): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT has_table_privilege(?, c.oid, 'SELECT') AS s, has_table_privilege(?, c.oid, 'INSERT') AS i,
                   has_table_privilege(?, c.oid, 'UPDATE') AS u, has_table_privilege(?, c.oid, 'DELETE') AS d,
                   has_table_privilege(?, c.oid, 'TRUNCATE') AS t
            FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relname = ?
        SQL, [$role, $role, $role, $role, $role, $table]);

        return ($row->s ? 'S' : '').($row->i ? 'I' : '').($row->u ? 'U' : '').($row->d ? 'D' : '').($row->t ? 'T' : '');
    }

    private function asTenantA(callable $callback): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), $callback(...));
    }

    /** Runs the statement inside a savepoint so the surrounding test transaction stays usable. */
    private function expectQueryError(string $sqlState, callable $statement, ?Connection $connection = null): void
    {
        try {
            ($connection ?? DB::connection())->transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }
}
