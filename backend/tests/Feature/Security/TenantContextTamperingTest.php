<?php

namespace Tests\Feature\Security;

use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenancyState;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * SEC-07: what the runtime role `ate_app` can do to the RLS tenant context when an attacker
 * controls SQL on its connection (SQL injection), and which guarantees hold regardless.
 *
 * Tests in group `sec-07-repro` reproduce the attacks found in the audit. They failed before the
 * signed context (migrations 001300-001500) and now run with the rest of the suite; run them
 * alone with: php artisan test --group=sec-07-repro
 */
class TenantContextTamperingTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        foreach ([$this->orgA, $this->orgB] as $org) {
            (new Department)->forceFill(['organization_id' => $org->id, 'code' => 'DEP1', 'name' => "Departemen {$org->code}"])->save();
        }
    }

    // --- Guarantees that hold today -------------------------------------------------------

    public function test_tenant_context_sees_only_its_own_rows(): void
    {
        $this->asTenantA(function () {
            $this->assertSame(1, DB::table('departments')->count());
            $this->assertSame(0, DB::table('departments')->where('organization_id', $this->orgB->id)->count());
        });
    }

    public function test_empty_unknown_and_malformed_context_fail_closed(): void
    {
        $this->tenancy()->reset();
        try {
            $this->assertSame(0, DB::table('departments')->count());

            // The plain settings of the old design are ignored for the runtime role.
            DB::select("SELECT set_config('app.organization_id', ?, false), set_config('app.platform_context', 'on', false)", [$this->orgB->id]);
            $this->assertSame(0, DB::table('departments')->count());

            // A malformed or unsigned claim is an error, never "no context".
            $unsigned = sprintf('v1|1|%d|1|%d|system||.%s', DB::selectOne('SELECT pg_backend_pid() AS p')->p, time() + 600, str_repeat('0', 64));
            foreach (['not-a-claim', $unsigned] as $claim) {
                DB::select("SELECT set_config('app.context', ?, false)", [$claim]);
                $this->expectQueryError('42501', fn () => DB::table('departments')->count());
            }
        } finally {
            $this->tenancy()->enter(TenancyState::system());
        }
    }

    public function test_runtime_role_has_no_elevated_attributes_or_memberships(): void
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolcreatedb);
        $this->assertSame(0, DB::selectOne(
            'SELECT count(*) AS n FROM pg_auth_members WHERE member = (SELECT oid FROM pg_roles WHERE rolname = current_user)'
        )->n);
        $this->assertFalse(DB::selectOne("SELECT has_schema_privilege('public', 'CREATE') AS ok")->ok);
        $this->assertFalse(DB::selectOne("SELECT has_database_privilege(current_database(), 'CREATE') AS ok")->ok);
    }

    public function test_runtime_role_cannot_switch_role_or_disable_rls(): void
    {
        $owner = config('database.connections.pgsql_owner.username');

        $this->expectQueryError('42501', fn () => DB::statement("SET ROLE {$owner}"));
        $this->expectQueryError('42501', fn () => DB::statement('ALTER TABLE departments DISABLE ROW LEVEL SECURITY'));
        $this->expectQueryError('42501', fn () => DB::statement('ALTER TABLE departments NO FORCE ROW LEVEL SECURITY'));
        $this->expectQueryError('42501', fn () => DB::statement('DROP POLICY tenant_isolation ON departments'));
        $this->expectQueryError('42501', fn () => DB::statement(
            'CREATE OR REPLACE FUNCTION public.ate_current_org() RETURNS uuid LANGUAGE sql AS $$ SELECT NULL::uuid $$'
        ));
    }

    public function test_temporary_function_cannot_shadow_the_policy_function(): void
    {
        // ate_app has TEMP on the database. Policies reference functions by OID and pg_temp is
        // never searched for functions, so a temp look-alike is ignored.
        $this->asTenantA(function () {
            DB::statement(sprintf(
                "CREATE FUNCTION pg_temp.ate_current_org() RETURNS uuid LANGUAGE sql AS \$\$ SELECT '%s'::uuid \$\$",
                $this->orgB->id,
            ));
            DB::statement('SET LOCAL search_path = pg_temp, public');

            $this->assertSame(0, DB::table('departments')->where('organization_id', $this->orgB->id)->count());
        });
    }

    public function test_every_table_with_organization_id_has_forced_rls(): void
    {
        $unprotected = DB::select(<<<'SQL'
            SELECT c.relname FROM pg_class c
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'organization_id' AND NOT a.attisdropped
            WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r'
              AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
        SQL);

        $this->assertSame([], array_column($unprotected, 'relname'));
    }

    public function test_context_is_restored_after_requests_including_failed_ones(): void
    {
        $this->actingAs($this->adminA);

        $this->getJson('/api/assets')->assertOk();
        $this->assertSame(['org' => null, 'platform' => true], $this->dbContext());

        $this->getJson('/api/assets/'.Str::uuid7())->assertNotFound();
        $this->assertSame(['org' => null, 'platform' => true], $this->dbContext());
    }

    public function test_queue_worker_drops_the_context_before_each_job(): void
    {
        $this->tenancy()->enter(new TenancyState(new TenantContext($this->orgA, $this->adminA), null, false));

        event(new JobProcessing('database', $this->createStub(Job::class)));

        $this->assertSame(['org' => null, 'platform' => false], $this->dbContext());
        $this->tenancy()->enter(TenancyState::system());
    }

    public function test_aborted_transaction_leaves_php_and_database_context_in_agreement(): void
    {
        $this->asTenantA(function () {
            try {
                DB::transaction(fn () => $this->tenancy()->runAsTenant(
                    new TenantContext($this->orgB, $this->adminB),
                    fn () => DB::select('SELECT 1 / 0'),
                ));
                $this->fail('Expected a query error');
            } catch (QueryException) {
                // The restore inside the aborted transaction fails; the rollback reverts the setting.
            }

            $this->assertSame($this->orgA->id, $this->tenancy()->organizationId());
            $this->assertSame(['org' => $this->orgA->id, 'platform' => false], $this->dbContext());
        });
    }

    public function test_search_and_sort_input_never_reach_sql(): void
    {
        $this->actingAs($this->adminA);

        $this->getJson('/api/assets?'.http_build_query([
            'search' => "' OR 1=1; SELECT set_config('app.platform_context','on',false); --",
            'sort' => "name; SELECT set_config('app.platform_context','on',false)",
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(['org' => null, 'platform' => true], $this->dbContext());
    }

    // --- Reproductions of the SEC-07 attacks (failed before the mitigation) ------------------

    #[Group('sec-07-repro')]
    public function test_overwriting_organization_id_does_not_expose_another_tenant(): void
    {
        $this->asTenantA(function () {
            DB::select("SELECT set_config('app.organization_id', ?, false)", [$this->orgB->id]);

            $this->assertSame(0, DB::table('departments')->where('organization_id', $this->orgB->id)->count());
        });
    }

    #[Group('sec-07-repro')]
    public function test_turning_on_platform_context_does_not_bypass_rls(): void
    {
        $this->asTenantA(function () {
            DB::statement("SET app.platform_context = 'on'");

            $this->assertSame(1, DB::table('departments')->count());
        });
    }

    #[Group('sec-07-repro')]
    public function test_single_injected_statement_cannot_switch_context_for_itself(): void
    {
        // Prepared statements prevent stacked queries; a subquery in one statement is enough.
        $this->asTenantA(function () {
            $leaked = DB::selectOne(<<<'SQL'
                SELECT count(*) AS n FROM departments
                WHERE organization_id = ? AND (SELECT set_config('app.platform_context', 'on', true)) = 'on'
            SQL, [$this->orgB->id])->n;

            $this->assertSame(0, $leaked);
        });
    }

    #[Group('sec-07-repro')]
    public function test_context_cannot_be_replaced_after_it_was_established(): void
    {
        $this->asTenantA(function () {
            DB::transaction(function () {
                DB::statement(sprintf("SET LOCAL app.organization_id = '%s'", $this->orgB->id));

                $this->expectQueryError('42501', fn () => DB::table('departments')->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => $this->orgB->id,
                    'code' => 'EVIL', 'name' => 'Injected', 'created_at' => now(), 'updated_at' => now(),
                ]));
            });
        });
    }

    #[Group('sec-07-repro')]
    public function test_tenant_context_cannot_read_or_rewrite_accounts_of_other_tenants(): void
    {
        $this->asTenantA(function () {
            $this->assertFalse(DB::table('users')->where('id', $this->adminB->id)->exists());
            $this->assertBlocked(fn () => DB::table('users')->where('id', $this->adminB->id)
                ->update(['password' => Hash::make('attacker-chosen-password')]));
        });
    }

    #[Group('sec-07-repro')]
    public function test_tenant_context_cannot_create_a_platform_administrator(): void
    {
        $roleId = PlatformRole::query()->firstOrFail()->id;

        $this->asTenantA(function () use ($roleId) {
            $id = (string) Str::uuid7();
            $this->assertBlocked(fn () => DB::table('users')->insert([
                'id' => $id, 'user_type' => 'platform', 'name' => 'Injected', 'email' => 'injected@example.test',
                'password' => Hash::make('attacker-chosen-password'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->assertBlocked(fn () => DB::table('platform_user_roles')->insert(['user_id' => $id, 'platform_role_id' => $roleId]));
        });
    }

    #[Group('sec-07-repro')]
    public function test_context_switched_inside_a_rolled_back_transaction_does_not_desynchronise(): void
    {
        // Session-level settings are transactional: a rollback restores the value from the
        // start of the transaction even though PHP already restored its own state.
        $this->asTenantA(function () {
            DB::beginTransaction();
            try {
                // A transaction opened while in system context, closed after PHP left it.
                $this->tenancy()->runAsSystem(fn () => DB::beginTransaction());
                DB::rollBack();

                $this->assertSame($this->orgA->id, $this->tenancy()->organizationId());
                $this->assertSame(['org' => $this->orgA->id, 'platform' => false], $this->dbContext());
            } finally {
                DB::rollBack();
            }
        });
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function asTenantA(callable $callback): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), $callback(...));
    }

    /** @return array{org: ?string, platform: bool} */
    private function dbContext(): array
    {
        $row = DB::selectOne('SELECT ate_current_org()::text AS org, ate_platform_context() AS platform');

        return ['org' => $row->org, 'platform' => $row->platform];
    }

    /** Runs the statement inside a savepoint so the surrounding test transaction stays usable. */
    private function expectQueryError(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }

    /** The write must be rejected (privilege/RLS error) or match no rows. */
    private function assertBlocked(callable $statement): void
    {
        try {
            $result = DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['42501', 'P0001'], $e->getMessage());

            return;
        }
        $this->assertContains($result, [0, false], 'The write was accepted');
    }
}
