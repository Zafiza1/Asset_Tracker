<?php

namespace Tests\Feature\Security;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\ContextClaim;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SEC-07: the signed database context (migration 001300, ContextClaim) and its lifecycle in
 * Tenancy. Claims are made here with the real signer and installed key, then changed in the
 * one property each test is about.
 */
class SignedContextTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB] = $this->createOrganization('BETA');
    }

    // --- Verification ----------------------------------------------------------------------

    public function test_a_valid_claim_establishes_its_context(): void
    {
        $this->setClaim($this->sign('tenant', $this->orgA->id, $this->adminA->id));

        $this->assertSame(['org' => $this->orgA->id, 'platform' => false], $this->dbContext());
    }

    public function test_claim_signed_with_another_key_or_unknown_key_id_is_rejected(): void
    {
        $this->expectRejected($this->sign('system', key: 'base64:'.base64_encode(random_bytes(32))));
        $this->expectRejected($this->sign('system', kid: 99));
    }

    public function test_altered_claim_is_rejected(): void
    {
        $claim = $this->sign('tenant', $this->orgA->id, $this->adminA->id);

        $this->expectRejected(str_replace($this->orgA->id, $this->orgB->id, $claim));
        $this->expectRejected(str_replace('|tenant|', '|system|', $claim));
        $this->expectRejected(strtoupper($claim));
        $this->expectRejected($claim.'0');
    }

    public function test_expired_claim_is_rejected(): void
    {
        $this->expectRejected($this->sign('system', now: time() - ContextClaim::fromConfig()->ttl - 1), 'database context expired');
    }

    public function test_claim_is_bound_to_its_backend(): void
    {
        $this->expectRejected($this->sign('system', pid: $this->backend()['pid'] + 1), 'database context belongs to another connection');
        $this->expectRejected($this->sign('system', started: $this->backend()['started'] - 1), 'database context belongs to another connection');

        // A live claim copied to another connection of the same role is useless there.
        $claim = DB::selectOne("SELECT current_setting('app.context') AS c")->c;
        config(['database.connections.pgsql_replay' => config('database.connections.pgsql')]);
        $replay = DB::connection('pgsql_replay');
        try {
            $replay->select("SELECT set_config('app.context', ?, false)", [$claim]);
            $this->expectRejected(null, 'database context belongs to another connection', $replay);
        } finally {
            DB::purge('pgsql_replay');
        }
    }

    public function test_tenant_claim_requires_an_active_member_of_an_active_organization(): void
    {
        $outsider = $this->createTenantUser($this->orgB);
        $suspendedMember = $this->createTenantUser($this->orgA);
        DB::table('organization_members')->where('user_id', $suspendedMember->id)->update(['status' => 'suspended']);
        $suspendedAccount = $this->createTenantUser($this->orgA, attributes: ['status' => 'suspended']);

        $this->expectRejected($this->sign('tenant', $this->orgA->id, $outsider->id), 'database context is not authorized');
        $this->expectRejected($this->sign('tenant', $this->orgA->id, $suspendedMember->id), 'database context is not authorized');
        $this->expectRejected($this->sign('tenant', $this->orgA->id, $suspendedAccount->id), 'database context is not authorized');

        $this->orgA->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();
        $this->expectRejected($this->sign('tenant', $this->orgA->id, $this->adminA->id), 'database context is not authorized');
    }

    public function test_platform_claim_requires_an_active_platform_account(): void
    {
        $platform = $this->createPlatformAdmin();
        $this->setClaim($this->sign('platform', null, $platform->id));
        $this->assertTrue($this->dbContext()['platform']);

        $this->expectRejected($this->sign('platform', null, $this->adminA->id), 'database context is not authorized');
        $platform->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->expectRejected($this->sign('platform', null, $platform->id), 'database context is not authorized');
    }

    public function test_signer_refuses_fields_that_do_not_fit_the_mode(): void
    {
        $claims = ContextClaim::fromConfig();
        foreach ([
            ['tenant', $this->orgA->id, null], ['tenant', null, $this->adminA->id], ['platform', $this->orgA->id, $this->adminA->id],
            ['system', null, $this->adminA->id], ['root', null, null], ['tenant', strtoupper($this->orgA->id), $this->adminA->id],
            ['tenant', $this->orgA->id.'|', $this->adminA->id],
        ] as [$mode, $org, $user]) {
            try {
                $claims->sign($mode, $org, $user, 1, 1, time());
                $this->fail("Signed {$mode}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_runtime_role_cannot_read_the_key_or_impersonate_the_context_role(): void
    {
        $this->expectQueryError('42501', fn () => DB::select('SELECT * FROM ate_private.context_keys'));
        $this->expectQueryError('42501', fn () => DB::select("SELECT ate_ctx.is_decimal('1', 1)"));
        $this->expectQueryError('42501', fn () => DB::statement('SET ROLE '.config('database.context_role')));
        $this->expectQueryError('42501', fn () => DB::statement('CREATE FUNCTION ate_ctx.f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'));
    }

    public function test_policies_verify_the_claim_once_per_statement(): void
    {
        $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, DB::select('EXPLAIN (COSTS OFF) SELECT * FROM departments')));
        $this->assertStringContainsString('InitPlan', $plan);

        $unwrapped = DB::select(<<<'SQL'
            SELECT tablename, policyname FROM pg_policies WHERE schemaname = 'public'
              AND concat(qual, ' ', with_check) ~ '(?<!SELECT )ate_(current_org|current_user|platform_context|system_context)\(\)'
        SQL);
        $this->assertSame([], $unwrapped);
    }

    // --- Lifecycle (Tenancy) -----------------------------------------------------------------

    public function test_claim_never_appears_in_query_events_or_the_query_log(): void
    {
        $logged = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$logged) {
            $logged[] = $e->sql.' '.json_encode($e->bindings);
        });
        DB::enableQueryLog();

        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), fn () => DB::table('departments')->count());
        $claim = DB::connection()->getPdo()->query("SELECT current_setting('app.context')")->fetchColumn();

        $this->assertNotSame('', $claim);
        $everything = implode("\n", [...$logged, ...array_map(fn ($q) => $q['query'].' '.json_encode($q['bindings']), DB::getQueryLog())]);
        $this->assertStringNotContainsString('app.context', $everything);
        $this->assertStringNotContainsString(substr($claim, -64), $everything);
    }

    public function test_claim_is_renewed_after_half_its_lifetime(): void
    {
        $first = $this->currentClaim();
        DB::select('SELECT 1');
        $this->assertSame($first, $this->currentClaim());

        $this->travel(intdiv(ContextClaim::fromConfig()->ttl, 2) + 1)->seconds();
        DB::select('SELECT 1');

        $renewed = $this->currentClaim();
        $this->assertNotSame($first, $renewed);
        $this->assertGreaterThan((int) explode('|', $first)[4], (int) explode('|', $renewed)[4]);
        $this->assertTrue($this->dbContext()['platform']);
    }

    public function test_a_new_backend_gets_the_current_context(): void
    {
        $before = $this->backend()['pid'];

        // Leaves the test transaction behind: nothing below writes.
        DB::reconnect();

        $this->assertNotSame($before, $this->backend()['pid']);
        $this->assertSame(['org' => null, 'platform' => true], $this->dbContext());
    }

    public function test_failure_inside_a_nested_context_keeps_the_original_error(): void
    {
        $memberB = $this->createTenantUser($this->orgB);

        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), function () use ($memberB) {
            try {
                DB::transaction(fn () => $this->tenancy()->runAsTenant(
                    new TenantContext($this->orgB, $memberB),
                    fn () => DB::select('SELECT 1 / 0'),
                ));
                $this->fail('Expected a query error');
            } catch (QueryException $e) {
                $this->assertSame('22012', $e->getCode(), 'The restore must not replace the original error');
            }

            $this->assertSame(['org' => $this->orgA->id, 'platform' => false], $this->dbContext());
        });
    }

    public function test_only_tenancy_sets_the_context_or_controls_transactions_in_sql(): void
    {
        $allowed = ['Domain/Shared/Tenancy/Tenancy.php', 'Domain/Shared/Tenancy/ContextClaim.php', 'Console/Commands/DatabaseContextKey.php'];
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            $code = $file->getContents();
            $settings = preg_match('/set_config|app\.(context|organization_id|platform_context)/', $code);
            $transactions = preg_match('/[\'"]\s*(BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|SET\s+(LOCAL\s+)?ROLE|RESET)\b/i', $code);
            if (($settings || $transactions) && ! in_array($path, $allowed, true)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Session settings and transaction control belong to Tenancy and Laravel (TransactionRolledBack re-syncs the context).');
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function sign(string $mode, ?string $org = null, ?string $user = null, ?int $pid = null, ?int $started = null, ?int $now = null, ?int $kid = null, ?string $key = null): string
    {
        $original = config('database.context');
        config(['database.context.kid' => $kid ?? $original['kid'], 'database.context.key' => $key ?? $original['key']]);
        try {
            $backend = $this->backend();

            return ContextClaim::fromConfig()->sign($mode, $org, $user, $pid ?? $backend['pid'], $started ?? $backend['started'], $now ?? time());
        } finally {
            config(['database.context' => $original]);
        }
    }

    /** @return array{pid: int, started: int} */
    private function backend(): array
    {
        $row = DB::selectOne('SELECT pg_backend_pid() AS pid, (EXTRACT(epoch FROM backend_start) * 1000000)::bigint AS started FROM pg_stat_get_activity(pg_backend_pid())');

        return ['pid' => (int) $row->pid, 'started' => (int) $row->started];
    }

    private function currentClaim(): string
    {
        return DB::connection()->getPdo()->query("SELECT current_setting('app.context')")->fetchColumn();
    }

    private function setClaim(string $claim, ?Connection $connection = null): void
    {
        ($connection ?? DB::connection())->getPdo()->prepare("SELECT set_config('app.context', ?, false)")->execute([$claim]);
    }

    /** The claim (or the one already set on $connection) must make every policy check fail. */
    private function expectRejected(?string $claim, string $message = 'invalid database context', ?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        if ($claim !== null) {
            $this->setClaim($claim, $connection);
        }
        try {
            // In a savepoint: its rollback also makes Tenancy restore the test's own context.
            $connection->transaction(fn () => $connection->select('SELECT ate_platform_context()'));
            $this->fail('Claim accepted: '.$claim);
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @return array{org: ?string, platform: bool} */
    private function dbContext(): array
    {
        $row = DB::selectOne('SELECT ate_current_org()::text AS org, ate_platform_context() AS platform');

        return ['org' => $row->org, 'platform' => $row->platform];
    }

    private function expectQueryError(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }
}
