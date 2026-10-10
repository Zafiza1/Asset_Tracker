<?php

namespace Tests;

use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenancyState;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Migrations run through the owner connection; tests and the app use the non-owner
 * runtime role, so Row-Level Security is active exactly as in production.
 *
 * Test code itself runs in "system" context (it needs to arrange data for several
 * tenants). HTTP requests establish their own tenant/platform context in middleware
 * and restore the previous one afterwards.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public const PASSWORD = UserFactory::PASSWORD;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenancy()->enter(TenancyState::system());
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_owner',
            '--drop-views' => true,
            '--seed' => true,
        ];
    }

    /**
     * Guards are resolved once per application; in production every request gets a new
     * application, but tests reuse it. Forget cached guards so switching users works.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard ?? 'web');
    }

    protected function tenancy(): Tenancy
    {
        return $this->app->make(Tenancy::class);
    }

    /** @return array{organization: Organization, admin: User} */
    protected function createOrganization(string $code, string $status = Organization::STATUS_ACTIVE): array
    {
        $result = $this->app->make(OrganizationProvisioner::class)->provision(
            ['code' => $code, 'name' => "Organisasi {$code}"],
            ['name' => "Admin {$code}", 'email' => strtolower($code).'-admin@example.test', 'password' => self::PASSWORD],
            adminMustChangePassword: false,
        );
        if ($status !== Organization::STATUS_ACTIVE) {
            $result['organization']->forceFill(['status' => $status])->save();
        }

        return $result;
    }

    /**
     * @param  list<string>  $roleCodes  role codes inside the organization
     * @param  list<array{scope_type: string, ref_id?: string}>  $scopes
     */
    protected function createTenantUser(Organization $organization, array $roleCodes = [], array $scopes = [['scope_type' => 'organization']], array $attributes = []): User
    {
        $user = User::factory()->forOrganization($organization)->create($attributes);
        foreach ($roleCodes as $code) {
            $role = Role::query()->where('organization_id', $organization->id)->where('code', $code)->firstOrFail();
            $user->roles()->attach($role->id, ['organization_id' => $organization->id]);
        }
        foreach ($scopes as $scope) {
            UserDataScope::grant($organization->id, $user->id, $scope['scope_type'], $scope['ref_id'] ?? null);
        }

        return $user;
    }

    protected function createPlatformAdmin(bool $withRole = true): User
    {
        $user = User::factory()->platform()->create();
        if ($withRole) {
            $role = PlatformRole::query()->where('code', RoleTemplates::PLATFORM_ADMIN)->firstOrFail();
            DB::table('platform_user_roles')->insert(['user_id' => $user->id, 'platform_role_id' => $role->id]);
        }

        return $user;
    }

    protected function roleId(Organization $organization, string $code): string
    {
        return Role::query()->where('organization_id', $organization->id)->where('code', $code)->value('id');
    }

    /** Asserts the standard error envelope with the given code. */
    protected function assertErrorCode(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
    }
}
