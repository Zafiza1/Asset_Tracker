<?php

namespace Tests;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\OrganizationInvitationNotification;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenancyState;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
    use RefreshDatabase {
        migrateDatabases as migrateFresh;
    }

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

    /** migrate:fresh recreates the context key store; the runtime role needs the key (SEC-07). */
    protected function migrateDatabases()
    {
        $this->migrateFresh();
        $this->artisan('db:context-key', ['action' => 'install'])->assertSuccessful();
    }

    /**
     * Requests start without any context, as in production (PHP state does not carry over
     * between requests), and the test's own context is restored afterwards.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $previous = $this->tenancy()->enter(new TenancyState(null, null, false));
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->tenancy()->restore($previous);
        }
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
    protected function createOrganization(string $code, string $status = Organization::STATUS_ACTIVE, int $seats = 50): array
    {
        $result = $this->app->make(OrganizationProvisioner::class)->provision(
            ['code' => $code, 'name' => "Organisasi {$code}"],
            ['name' => "Admin {$code}", 'email' => strtolower($code).'-admin@example.test', 'password' => self::PASSWORD],
            adminMustChangePassword: false,
        );
        $this->grantLicense($result['organization'], $seats);
        if ($status !== Organization::STATUS_ACTIVE) {
            $result['organization']->forceFill(['status' => $status])->save();
        }

        return $result;
    }

    /**
     * A base license plus $seats extra seats, valid for a year (system context).
     * Returns the license(s) created, base first.
     *
     * @return list<OrganizationLicense>
     */
    protected function grantLicense(Organization $organization, int $seats = 50, ?Carbon $expiresAt = null, bool $base = true): array
    {
        $licensing = $this->app->make(Licensing::class);
        $expiresAt ??= now()->addYear();
        $granted = [];
        if ($base) {
            $granted[] = $licensing->grant($organization, OrganizationLicense::KIND_BASE, 1, now()->subMinute(), $expiresAt);
        }
        if ($seats > 0) {
            $granted[] = $licensing->grant($organization, OrganizationLicense::KIND_SEATS, $seats, now()->subMinute(), $expiresAt);
        }

        return $granted;
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

    /** Gives an existing tenant account access to another organization (same login). */
    protected function addMembership(User $user, Organization $organization, string $roleCode, string $status = User::STATUS_ACTIVE): void
    {
        (new OrganizationMember)->forceFill(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => $status])->save();
        $user->roles()->attach($this->roleId($organization, $roleCode), ['organization_id' => $organization->id]);
        UserDataScope::grant($organization->id, $user->id, 'organization');
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

    /**
     * One record of every tenant master type for an organization, created directly (system context):
     * department, location, category, adopted definition GEN-UMUM with draft v1 + attribute.
     *
     * @return array{department: Department, location: Location, category: TenantAssetCategory,
     *     definition: TenantAssetDefinition, version: TenantDefinitionVersion, attribute: TenantAttributeDefinition}
     */
    protected function createMasterFixtures(Organization $organization, string $suffix = '1'): array
    {
        $org = ['organization_id' => $organization->id];
        $department = (new Department)->forceFill([...$org, 'code' => "DEP{$suffix}", 'name' => "Departemen {$suffix}"]);
        $department->save();
        $location = (new Location)->forceFill([...$org, 'code' => "LOC{$suffix}", 'name' => "Gudang {$suffix}",
            'location_type_id' => LocationType::query()->where('code', 'GUDANG')->value('id')]);
        $location->save();
        $category = (new TenantAssetCategory)->forceFill([...$org, 'code' => "CAT{$suffix}", 'name' => "Kategori {$suffix}",
            'generic_category_id' => GenericAssetCategory::query()->where('code', 'UMUM')->value('id')]);
        $category->save();

        $generic = GenericAssetDefinition::query()->where('code', 'GEN-UMUM')->firstOrFail();
        $definition = (new TenantAssetDefinition)->forceFill([...$org, 'code' => "DEF{$suffix}", 'name' => "Definisi {$suffix}",
            'generic_definition_id' => $generic->id, 'tenant_category_id' => $category->id]);
        $definition->save();
        $version = (new TenantDefinitionVersion)->forceFill([...$org, 'tenant_definition_id' => $definition->id, 'version_number' => 1,
            'generic_definition_version_id' => $generic->versions()->where('status', GenericDefinitionVersion::RELEASED)->value('id')]);
        $version->save();
        $attribute = (new TenantAttributeDefinition)->forceFill([...$org, 'tenant_definition_version_id' => $version->id,
            'key' => 'catatan_internal', 'label' => 'Catatan Internal', 'data_type' => 'string']);
        $attribute->save();

        return [
            'department' => $department,
            'location' => $location,
            'category' => $category,
            'definition' => $definition,
            'version' => $version,
            'attribute' => $attribute,
        ];
    }

    /**
     * An asset with its registration transaction, created directly (system context).
     *
     * @param  array{location: Location, category: TenantAssetCategory}  $masters
     * @return array{asset: Asset, transaction: AssetTransaction}
     */
    protected function createAssetFixture(Organization $organization, array $masters, string $suffix = '1'): array
    {
        $asset = (new Asset)->forceFill([
            'organization_id' => $organization->id, 'asset_number' => "FIX-{$suffix}", 'name' => "Aset {$suffix}",
            'category_id' => $masters['category']->id, 'location_id' => $masters['location']->id, 'status' => 'available',
        ]);
        $asset->save();
        $transaction = (new AssetTransaction)->forceFill([
            'organization_id' => $organization->id, 'transaction_number' => "REG-FIX-{$suffix}", 'asset_id' => $asset->id,
            'type' => 'registration', 'transaction_date' => now()->toDateString(),
            'to_location_id' => $asset->location_id, 'to_status' => 'available',
        ]);
        $transaction->save();

        return ['asset' => $asset, 'transaction' => $transaction];
    }

    protected function roleId(Organization $organization, string $code): string
    {
        return Role::query()->where('organization_id', $organization->id)->where('code', $code)->value('id');
    }

    /** The token in the invitation email sent to $email (requires Notification::fake()). */
    protected function sentInvitationToken(string $email, bool $last = false): string
    {
        $sent = Notification::sent(new AnonymousNotifiable, OrganizationInvitationNotification::class,
            fn ($notification, $channels, $notifiable) => mb_strtolower($notifiable->routes['mail'] ?? '') === mb_strtolower($email));
        $this->assertNotEmpty($sent, "No invitation was sent to {$email}");

        return ($last ? $sent->last() : $sent->first())->token;
    }

    /** Asserts the standard error envelope with the given code. */
    protected function assertErrorCode(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
    }
}
