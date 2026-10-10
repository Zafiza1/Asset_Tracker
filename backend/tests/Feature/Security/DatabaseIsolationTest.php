<?php

namespace Tests\Feature\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\MissingTenantContext;
use App\Domain\Shared\Tenancy\TenancyState;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * The database-level isolation layers: fail-closed global scope, composite foreign keys,
 * Row-Level Security, and append-only/immutability triggers.
 */
class DatabaseIsolationTest extends TestCase
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

    public function test_tenant_models_refuse_to_query_without_context(): void
    {
        $this->tenancy()->reset();

        try {
            Role::query()->count();
            $this->fail('Expected MissingTenantContext');
        } catch (MissingTenantContext) {
            $this->addToAssertionCount(1);
        } finally {
            $this->tenancy()->enter(TenancyState::system());
        }
    }

    public function test_row_level_security_hides_other_tenants_even_without_global_scope(): void
    {
        $this->asTenantA(function () {
            $this->assertSame(6, Role::query()->withoutGlobalScopes()->count());
            $this->assertSame(6, DB::table('roles')->count());
            $this->assertSame(0, DB::table('roles')->where('organization_id', $this->orgB->id)->count());
            $this->assertSame(1, DB::table('user_data_scopes')->count());
        });
    }

    public function test_row_level_security_hides_everything_without_context(): void
    {
        $this->tenancy()->reset();
        try {
            $this->assertSame(0, DB::table('roles')->count());
            $this->assertSame(0, DB::table('audit_logs')->count());
            $this->assertSame(0, DB::table('organization_settings')->count());
        } finally {
            $this->tenancy()->enter(TenancyState::system());
        }
    }

    public function test_row_level_security_rejects_writes_into_another_tenant(): void
    {
        $this->asTenantA(function () {
            $this->expectQueryError('42501', fn () => DB::table('branches')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->orgB->id,
                'code' => 'X', 'name' => 'X', 'created_at' => now(), 'updated_at' => now(),
            ]));
        });
    }

    public function test_model_creation_for_another_tenant_is_rejected_by_the_application(): void
    {
        $this->asTenantA(function () {
            $branch = new Branch(['code' => 'B1', 'name' => 'Cabang']);
            $branch->organization_id = $this->orgB->id;

            $this->expectException(LogicException::class);
            $branch->save();
        });
    }

    public function test_organization_id_of_a_record_cannot_be_changed(): void
    {
        $branch = new Branch(['code' => 'B1', 'name' => 'Cabang']);
        $branch->forceFill(['organization_id' => $this->orgA->id])->save();

        $branch->organization_id = $this->orgB->id;
        $this->expectException(LogicException::class);
        $branch->save();
    }

    public function test_composite_foreign_keys_reject_cross_tenant_relations(): void
    {
        $roleB = $this->roleId($this->orgB, 'VIEWER');

        // user of A + role of B, labelled as A
        $this->expectQueryError('23503', fn () => DB::table('user_roles')->insert([
            'organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'role_id' => $roleB,
        ]));
        // ... or labelled as B
        $this->expectQueryError('23503', fn () => DB::table('user_roles')->insert([
            'organization_id' => $this->orgB->id, 'user_id' => $this->adminA->id, 'role_id' => $roleB,
        ]));

        $branchB = new Branch(['code' => 'B1', 'name' => 'Cabang B']);
        $branchB->forceFill(['organization_id' => $this->orgB->id])->save();
        $this->expectQueryError('23503', fn () => DB::table('user_data_scopes')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id,
            'scope_type' => 'branch', 'branch_id' => $branchB->id,
        ]));
        $this->expectQueryError('23503', fn () => DB::table('users')->where('id', $this->adminA->id)->update(['home_branch_id' => $branchB->id]));
    }

    public function test_user_organization_and_type_are_immutable(): void
    {
        $this->expectQueryError('P0001', fn () => DB::table('users')->where('id', $this->adminA->id)->update(['organization_id' => $this->orgB->id]));
        $this->expectQueryError('P0001', fn () => DB::table('users')->where('id', $this->adminA->id)->update(['user_type' => 'platform', 'organization_id' => null]));
        $this->expectQueryError('P0001', fn () => DB::table('users')->where('id', $this->adminA->id)->delete());

        $this->assertSame($this->orgA->id, $this->adminA->fresh()->organization_id);
    }

    public function test_tenant_user_check_constraint(): void
    {
        $this->expectQueryError('23514', fn () => DB::table('users')->insert([
            'id' => (string) Str::uuid7(), 'user_type' => 'tenant', 'organization_id' => null,
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->expectQueryError('23514', fn () => DB::table('users')->insert([
            'id' => (string) Str::uuid7(), 'user_type' => 'platform', 'organization_id' => $this->orgA->id,
            'name' => 'X', 'email' => 'y@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_platform_and_tenant_permissions_and_roles_cannot_be_mixed(): void
    {
        $platformPermission = DB::table('permissions')->where('code', 'platform.organization.manage')->value('id');
        $tenantPermission = DB::table('permissions')->where('code', 'asset.view')->value('id');
        $platformRole = PlatformRole::query()->firstOrFail();

        $this->expectQueryError('P0001', fn () => DB::table('role_permissions')->insert([
            'organization_id' => $this->orgA->id, 'role_id' => $this->roleId($this->orgA, 'VIEWER'), 'permission_id' => $platformPermission,
        ]));
        $this->expectQueryError('P0001', fn () => DB::table('platform_role_permissions')->insert([
            'platform_role_id' => $platformRole->id, 'permission_id' => $tenantPermission,
        ]));
        $this->expectQueryError('P0001', fn () => DB::table('platform_user_roles')->insert([
            'user_id' => $this->adminA->id, 'platform_role_id' => $platformRole->id,
        ]));
    }

    public function test_audit_logs_are_append_only_even_in_system_context(): void
    {
        $log = $this->app->make(AuditLogger::class)->record('test.event', organizationId: $this->orgA->id);

        // The runtime role has no UPDATE/DELETE privilege at all...
        $this->expectQueryError('42501', fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']));
        $this->expectQueryError('42501', fn () => DB::table('audit_logs')->where('id', $log->id)->delete());
        $this->assertSame('test.event', AuditLog::query()->find($log->id)->action);
    }

    public function test_audit_trigger_blocks_table_owner_as_well(): void
    {
        $owner = DB::connection('pgsql_owner');
        $owner->beginTransaction();
        try {
            $owner->select("SELECT set_config('app.platform_context', 'on', true)");
            $id = (string) Str::uuid7();
            $owner->table('audit_logs')->insert(['id' => $id, 'actor_type' => 'system', 'action' => 'test.owner']);

            // FORCE RLS without any UPDATE/DELETE policy: even the owner matches zero rows.
            $this->assertSame(0, $owner->table('audit_logs')->where('id', $id)->update(['action' => 'tampered']));
            $this->assertSame(0, $owner->table('audit_logs')->where('id', $id)->delete());
            $this->assertSame('test.owner', $owner->table('audit_logs')->where('id', $id)->value('action'));
        } finally {
            $owner->rollBack();
        }

        // Third layer for roles that bypass RLS: the append-only trigger.
        $this->assertTrue((bool) DB::selectOne(
            "SELECT 1 AS ok FROM pg_trigger WHERE tgname = 'audit_logs_append_only' AND tgrelid = 'audit_logs'::regclass AND tgenabled = 'O'"
        ));
    }

    public function test_audit_logger_cannot_write_into_another_tenant_from_tenant_context(): void
    {
        $this->asTenantA(function () {
            $this->expectException(LogicException::class);
            $this->app->make(AuditLogger::class)->record('test.event', organizationId: $this->orgB->id);
        });
    }

    public function test_audit_logger_redacts_secrets(): void
    {
        $log = $this->app->make(AuditLogger::class)->record('test.event',
            after: ['name' => 'x', 'password' => 'p', 'nested' => ['api_key' => 'k', 'remember_token' => 't']],
            metadata: ['Authorization' => 'Bearer abc'],
            organizationId: $this->orgA->id,
        );

        $stored = AuditLog::query()->find($log->id);
        $this->assertSame('x', $stored->after['name']);
        $this->assertSame('[REDACTED]', $stored->after['password']);
        $this->assertSame('[REDACTED]', $stored->after['nested']['api_key']);
        $this->assertSame('[REDACTED]', $stored->after['nested']['remember_token']);
        $this->assertSame('[REDACTED]', $stored->metadata['Authorization']);
    }

    private function asTenantA(callable $callback): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), $callback(...));
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
}
