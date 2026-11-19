<?php

namespace Tests\Feature\Security;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\AssetTransactions\Models\TransactionAttachment;
use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\ReportDesigner\Models\ReportExport;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Models\ReportTemplateVersion;
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
        'api/auth/invitation/inspect', 'api/auth/invitation/register', 'api/auth/invitation/login', 'api/auth/mobile/login'];

    /** Authenticated routes that intentionally run without tenant/platform context. */
    private const SESSION_ONLY_ROUTES = ['api/auth/logout', 'api/auth/me', 'api/auth/password', 'api/auth/organization', 'api/auth/organizations',
        'api/auth/invitation/accept', 'api/auth/email/verification-notification', 'api/auth/email/verify',
        'api/auth/mobile/me', 'api/auth/mobile/logout'];

    /**
     * Tenant routes open to every member without a permission: the Notifications menu, where
     * each user follows their own approval requests (ApprovalController narrows the rows; only
     * approve/reject need approval.manage), and the home page, whose cards each check their own
     * permission (HomeCards::CARDS) and whose card order is the member's own preference.
     */
    private const MEMBER_ROUTES = ['api/home/cards', 'api/home/preferences', 'api/approvals', 'api/approvals/summary', 'api/approvals/counts', 'api/approvals/{approvalRequest}', 'api/approvals/{approvalRequest}/cancel'];

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
            if (in_array($uri, self::MEMBER_ROUTES, true)) {
                continue;
            }
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
            // {module} is a key of the fixed module catalog, not a record; each organization's
            // switches are covered by OrganizationModuleTest::test_modules_are_per_organization.
            if (str_starts_with($route->uri(), 'api/modules/')) {
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
        $categoryAttribute = (new TenantCategoryAttribute)->forceFill(['organization_id' => $orgB->id, 'category_id' => $masters['category']->id,
            'key' => 'nomor_polisi', 'label' => 'Nomor Polisi', 'data_type' => 'string']);
        $categoryAttribute->save();
        $invitation = (new OrganizationInvitation)->forceFill(['organization_id' => $orgB->id, 'email' => 'tamu@beta.test',
            'token_hash' => OrganizationInvitation::hashToken('fixture'), 'invited_by' => $adminB->id,
            'role_ids' => [$this->roleId($orgB, 'SUPERVISOR')], 'scopes' => [['scope_type' => 'organization']], 'expires_at' => now()->addDay()]);
        $invitation->save();
        $approval = (new ApprovalRequest)->forceFill(['organization_id' => $orgB->id, 'category' => 'master', 'action' => 'location.create',
            'summary' => 'Gudang', 'method' => 'POST', 'path' => 'api/locations', 'payload' => ['name' => 'Gudang'], 'requested_by' => $adminB->id]);
        $approval->save();
        $template = (new ReportTemplate)->forceFill(['organization_id' => $orgB->id, 'code' => 'B-TPL', 'name' => 'Beta',
            'data_source' => 'asset', 'status' => 'draft']);
        $template->save();
        $version = (new ReportTemplateVersion)->forceFill(['organization_id' => $orgB->id, 'template_id' => $template->id,
            'version_number' => 1, 'status' => 'draft', 'design' => []]);
        $version->save();
        $export = (new ReportExport)->forceFill(['organization_id' => $orgB->id, 'template_id' => $template->id,
            'template_version_id' => $version->id, 'export_number' => 'RPT-2026-000001', 'format' => 'pdf', 'status' => 'completed',
            'created_by' => $adminB->id, 'completed_at' => now(), 'expires_at' => now()->addDays(30)]);
        $export->save();
        $transactionType = (new TransactionTypeDefinition)->forceFill(['organization_id' => $orgB->id, 'code' => 'MUT', 'name' => 'Mutasi', 'status' => 'active']);
        $transactionType->save();
        $flowVersion = (new TransactionFlowVersion)->forceFill(['organization_id' => $orgB->id, 'type_definition_id' => $transactionType->id,
            'version_number' => 1, 'status' => 'draft', 'graph' => ['nodes' => [], 'edges' => []]]);
        $flowVersion->save();
        $run = (new TransactionRun)->forceFill(['organization_id' => $orgB->id, 'type_definition_id' => $transactionType->id,
            'flow_version_id' => $flowVersion->id, 'transaction_number' => 'MUT-2026-000001', 'state' => 'running',
            'transaction_date' => now()->toDateString(), 'created_by' => $adminB->id]);
        $run->save();
        $task = (new TransactionTask)->forceFill(['organization_id' => $orgB->id, 'run_id' => $run->id, 'node_id' => 'isi',
            'kind' => 'input', 'assignee_type' => 'user', 'assignee_id' => $adminB->id]);
        $task->save();
        $attachment = (new TransactionAttachment)->forceFill(['organization_id' => $orgB->id, 'type_definition_id' => $transactionType->id,
            'field_key' => 'berkas', 'path' => 'transactions/x/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 1, 'uploaded_by' => $adminB->id]);
        $attachment->save();
        $dashboard = (new Dashboard)->forceFill(['organization_id' => $orgB->id, 'name' => 'Beta', 'dashboard_type' => 'custom',
            'visibility' => 'organization', 'created_by' => $adminB->id]);
        $dashboard->save();
        $catalog = WidgetCatalog::query()->firstOrFail();
        $widget = (new DashboardWidget)->forceFill(['organization_id' => $orgB->id, 'dashboard_id' => $dashboard->id,
            'widget_catalog_id' => $catalog->id, 'configuration' => [], 'layout' => $catalog->default_layout,
            'sort_order' => 0, 'is_visible' => true]);
        $widget->save();
        $dashboardTemplate = (new DashboardTemplate)->forceFill(['organization_id' => $orgB->id, 'template_key' => 'beta', 'name' => 'Beta',
            'dashboard_type' => 'custom', 'scope' => 'organization']);
        $dashboardTemplate->save();

        return [
            'dashboard' => $dashboard->id,
            'widget' => $widget->id,
            'dashboardTemplate' => $dashboardTemplate->id,
            // {source} is a registry key, not a record: an unknown one answers 404 for everyone.
            'source' => 'bukan-sumber',
            'reportTemplate' => $template->id,
            'reportExport' => $export->id,
            'transactionType' => $transactionType->id,
            'transactionRun' => $run->id,
            'task' => $task->id,
            'transactionAttachment' => $attachment->id,
            'approvalRequest' => $approval->id,
            'invitation' => $invitation->id,
            'asset' => $asset->id,
            'assetTransaction' => $transaction->id,
            'user' => $adminB->id,
            'role' => $this->roleId($orgB, 'SUPERVISOR'),
            'department' => $masters['department']->id,
            'location' => $masters['location']->id,
            'tenantCategory' => $masters['category']->id,
            'tenantDefinition' => $masters['definition']->id,
            'tenantVersion' => $masters['version']->id,
            'tenantAttribute' => $masters['attribute']->id,
            'categoryAttribute' => $categoryAttribute->id,
            'licenseCode' => $this->licenseCodeId($orgB),
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
