<?php

namespace Tests\Feature\Security;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
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
    private const PUBLIC_ROUTES = ['api/health', 'api/health/ready', 'api/auth/login'];

    /** Authenticated routes that intentionally run without tenant/platform context. */
    private const SESSION_ONLY_ROUTES = ['api/auth/logout', 'api/auth/me', 'api/auth/password'];

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
        return [
            'user' => $adminB->id,
            'role' => $this->roleId($orgB, 'VIEWER'),
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
