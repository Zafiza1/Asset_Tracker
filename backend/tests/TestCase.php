<?php

namespace Tests;

use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenancyState;
use App\Domain\TenantMaster\Models\AssetCondition;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetDefinition;
use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
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

    /**
     * One record of every tenant master type for an organization, created directly (system context):
     * branch, department, location, category, condition, adopted definition GEN-UMUM with draft v1 + attribute.
     *
     * @return array{branch: Branch, department: Department, location: Location, category: TenantAssetCategory,
     *     condition: AssetCondition, definition: TenantAssetDefinition, version: TenantDefinitionVersion, attribute: TenantAttributeDefinition}
     */
    protected function createMasterFixtures(Organization $organization, string $suffix = '1'): array
    {
        $org = ['organization_id' => $organization->id];
        $branch = (new Branch)->forceFill([...$org, 'code' => "BR{$suffix}", 'name' => "Cabang {$suffix}"]);
        $branch->save();
        $department = (new Department)->forceFill([...$org, 'code' => "DEP{$suffix}", 'name' => "Departemen {$suffix}", 'branch_id' => $branch->id]);
        $department->save();
        $location = (new Location)->forceFill([...$org, 'code' => "LOC{$suffix}", 'name' => "Gudang {$suffix}", 'branch_id' => $branch->id,
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
            'branch' => $branch,
            'department' => $department,
            'location' => $location,
            'category' => $category,
            'condition' => AssetCondition::query()->where('organization_id', $organization->id)->orderBy('sort_order')->firstOrFail(),
            'definition' => $definition,
            'version' => $version,
            'attribute' => $attribute,
        ];
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
