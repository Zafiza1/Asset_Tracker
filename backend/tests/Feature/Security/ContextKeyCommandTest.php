<?php

namespace Tests\Feature\Security;

use App\Domain\Shared\Tenancy\ContextClaim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEC-07: `php artisan db:context-key`. Runs through the owner connection outside the test
 * transaction, so it only adds key ids above the one the suite uses (DB_CONTEXT_KID).
 */
class ContextKeyCommandTest extends TestCase
{
    public function test_status_lists_key_ids_without_key_material(): void
    {
        Artisan::call('db:context-key', ['action' => 'status']);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s*'.config('database.context.kid').'\s*\|\s*(true|1)\s*\|/', $output);
        $key = ContextClaim::decodeKey(config('database.context.key'));
        foreach ([config('database.context.key'), bin2hex($key), ...array_map('bin2hex', ContextClaim::pads($key))] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_install_is_idempotent_and_refuses_another_key_under_the_same_id(): void
    {
        $this->artisan('db:context-key', ['action' => 'install'])->expectsOutputToContain('unchanged')->assertSuccessful();

        config(['database.context.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('db:context-key', ['action' => 'install'])->expectsOutputToContain('already holds a different key')->assertFailed();
    }

    public function test_rotation_adds_a_key_and_deactivation_rejects_its_claims_at_once(): void
    {
        $kid = (int) config('database.context.kid') + 1 + random_int(0, 1000);
        $original = config('database.context');
        config(['database.context.kid' => $kid, 'database.context.key' => 'base64:'.base64_encode(random_bytes(32))]);
        try {
            $this->artisan('db:context-key', ['action' => 'install'])->expectsOutputToContain('installed')->assertSuccessful();
            $claim = $this->systemClaim();

            $this->assertTrue($this->platformContextWith($claim));
            $this->artisan('db:context-key', ['action' => 'deactivate', '--kid' => $kid])->expectsOutputToContain('deactivated')->assertSuccessful();
            $this->assertNull($this->platformContextWith($claim));
        } finally {
            config(['database.context' => $original]);
        }
    }

    private function systemClaim(): string
    {
        $row = DB::selectOne('SELECT pg_backend_pid() AS pid, (EXTRACT(epoch FROM backend_start) * 1000000)::bigint AS started FROM pg_stat_get_activity(pg_backend_pid())');

        return ContextClaim::fromConfig()->sign('system', null, null, (int) $row->pid, (int) $row->started, time());
    }

    /** true when the claim is accepted, null when it is rejected (42501). */
    private function platformContextWith(string $claim): ?bool
    {
        DB::connection()->getPdo()->prepare("SELECT set_config('app.context', ?, false)")->execute([$claim]);
        try {
            return DB::transaction(fn () => DB::selectOne('SELECT ate_platform_context() AS p')->p);
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());

            return null;
        }
    }
}
