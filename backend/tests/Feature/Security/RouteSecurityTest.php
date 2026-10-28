<?php

namespace Tests\Feature\Security;

use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Structural guarantees over every API route, so new endpoints cannot silently skip
 * authentication, tenant resolution, or permission checks.
 */
class RouteSecurityTest extends TestCase
{
    /** Routes reachable without a session. */
    private const PUBLIC_ROUTES = ['api/health', 'api/health/ready', 'api/auth/login', 'api/auth/register',
        'api/auth/invitation/inspect', 'api/auth/invitation/register'];

    /** Authenticated routes that intentionally run without tenant/platform context. */
    private const SESSION_ONLY_ROUTES = ['api/auth/logout', 'api/auth/me', 'api/auth/password', 'api/auth/organization', 'api/auth/organizations',
        'api/auth/invitation/accept', 'api/auth/email/verification-notification', 'api/auth/email/verify'];

    public function test_every_api_route_is_authenticated_scoped_and_permission_checked(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            if (in_array($uri, self::PUBLIC_ROUTES, true)) {
                continue;
            }
            $this->assertContains('auth:sanctum', $middleware, "{$uri} must require authentication");
            if (in_array($uri, self::SESSION_ONLY_ROUTES, true)) {
                continue;
            }

            $context = str_starts_with($uri, 'api/platform/') ? 'platform' : 'tenant';
            $this->assertContains($context, $middleware, "{$uri} must establish the {$context} context");
            $this->assertNotEmpty(
                array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'permission:')),
                "{$uri} must declare a permission",
            );
            $checked++;
        }

        $this->assertGreaterThan(15, $checked);
    }

    public function test_every_tenant_route_with_an_id_returns_404_for_another_tenants_record(): void
    {
        ['admin' => $adminA] = $this->createOrganization('ALPHA');
        ['organization' => $orgB, 'admin' => $adminB] = $this->createOrganization('BETA');
        $foreignIds = $this->foreignIds($orgB, $adminB);
        $this->actingAs($adminA, 'web');

        $checked = 0;
        foreach ($this->apiRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/platform/') || $route->parameterNames() === []) {
                continue;
            }
            // The read-only Generic Master catalog is shared by all tenants by design
            // (covered by MasterDataSecurityTest instead).
            if (str_starts_with($route->uri(), 'api/generic-masters/')) {
                continue;
            }
            $uri = '/'.$route->uri();
            foreach ($route->parameterNames() as $name) {
                $this->assertArrayHasKey($name, $foreignIds, "Add a foreign-tenant fixture for route parameter {{$name}} ({$uri})");
                $uri = str_replace('{'.$name.'}', $foreignIds[$name], $uri);
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, $uri, []);
                $this->assertSame(404, $response->status(), "{$method} {$uri} must not reach another tenant's record");
                $checked++;
            }
        }

        $this->assertGreaterThan(5, $checked);
    }

    /** @return array<string, string> */
    private function foreignIds(Organization $orgB, User $adminB): array
    {
        $masters = $this->createMasterFixtures($orgB);
        ['asset' => $asset, 'transaction' => $transaction] = $this->createAssetFixture($orgB, $masters);
        $locationType = (new LocationType)->forceFill(['organization_id' => $orgB->id, 'code' => 'KHUSUS', 'name' => 'Khusus']);
        $locationType->save();
        $categoryAttribute = (new TenantCategoryAttribute)->forceFill(['organization_id' => $orgB->id, 'category_id' => $masters['category']->id,
            'key' => 'nomor_polisi', 'label' => 'Nomor Polisi', 'data_type' => 'string']);
        $categoryAttribute->save();
        $invitation = (new OrganizationInvitation)->forceFill(['organization_id' => $orgB->id, 'email' => 'tamu@beta.test',
            'token_hash' => OrganizationInvitation::hashToken('fixture'), 'invited_by' => $adminB->id,
            'role_ids' => [$this->roleId($orgB, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']], 'expires_at' => now()->addDay()]);
        $invitation->save();

        return [
            'invitation' => $invitation->id,
            'asset' => $asset->id,
            'assetTransaction' => $transaction->id,
            'user' => $adminB->id,
            'role' => $this->roleId($orgB, 'SUPERVISOR'),
            'department' => $masters['department']->id,
            'location' => $masters['location']->id,
            'locationType' => $locationType->id,
            'tenantCategory' => $masters['category']->id,
            'tenantDefinition' => $masters['definition']->id,
            'tenantVersion' => $masters['version']->id,
            'tenantAttribute' => $masters['attribute']->id,
            'categoryAttribute' => $categoryAttribute->id,
        ];
    }

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/'),
        ));
    }
}
