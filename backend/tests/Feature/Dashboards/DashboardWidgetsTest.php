<?php

namespace Tests\Feature\Dashboards;

use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Widget CRUD and per-widget data (F-06 §4.4): configuration validated against the catalog
 * schema as data, edit-level access on the dashboard, and each widget's own data permission
 * plus the member's data scope — never the dashboard's visibility.
 */
class DashboardWidgetsTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    /** dashboard.view + asset.view + transaction.view: reads widget data. */
    private User $assetViewer;

    /** dashboard.view only: dashboards open, widget data is denied per widget. */
    private User $plainViewer;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        $this->createRole($this->orgA, 'DASH_ASSET', ['dashboard.view', 'dashboard.create', 'asset.view', 'transaction.view']);
        $this->createRole($this->orgA, 'DASH_VIEW', ['dashboard.view']);
        $this->assetViewer = $this->createTenantUser($this->orgA, ['DASH_ASSET']);
        $this->plainViewer = $this->createTenantUser($this->orgA, ['DASH_VIEW']);
    }

    public function test_widgets_can_be_added_configured_hidden_reordered_and_deleted(): void
    {
        $this->actingAs($this->adminA);
        $dashboard = $this->customDashboard($this->adminA, $this->orgA, 'organization');
        $this->share($this->orgA, $dashboard->id, ['user_id' => $this->plainViewer->id], 'view');
        $catalog = WidgetCatalog::query()->where('widget_key', 'total_assets')->firstOrFail();

        // A viewer cannot edit widgets.
        $this->actingAs($this->plainViewer);
        $this->assertErrorCode($this->postJson("/api/dashboards/{$dashboard->id}/widgets", ['widget_catalog_id' => $catalog->id]), 403, 'FORBIDDEN');

        $this->actingAs($this->adminA);
        $created = $this->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_catalog_id' => $catalog->id,
            'title' => 'Total Aset Aktif',
            'configuration' => ['include_archived' => true],
        ])->assertCreated()->json('data');
        $this->assertSame('total_assets', $created['widget_key']);
        $this->assertSame('Total Aset Aktif', $created['title']);
        $this->assertTrue($created['configuration']['include_archived']);

        // Configuration is schema data: unknown keys and out-of-range values are rejected.
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$dashboard->id}/widgets/{$created['id']}",
            ['configuration' => ['no_such_key' => 1]]), 422, 'INVALID_WIDGET_CONFIGURATION');
        $second = WidgetCatalog::query()->where('widget_key', 'assets_by_category')->firstOrFail();
        $this->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_catalog_id' => $second->id, 'configuration' => ['limit' => 999],
        ])->assertStatus(422);
        $this->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_catalog_id' => $second->id, 'configuration' => ['limit' => 5],
        ])->assertCreated();

        // Update: retitle, hide, valid configuration.
        $this->patchJson("/api/dashboards/{$dashboard->id}/widgets/{$created['id']}", [
            'title' => null, 'is_visible' => false, 'configuration' => ['include_archived' => false],
        ])->assertOk()->assertJsonPath('data.is_visible', false)->assertJsonPath('data.custom_title', null);

        // Reorder must name every widget exactly once.
        $widgets = Dashboard::query()->findOrFail($dashboard->id)->widgets()->orderBy('sort_order')->get();
        $this->assertErrorCode($this->putJson("/api/dashboards/{$dashboard->id}/widgets-order",
            ['order' => [$widgets[0]->id]]), 422, 'INVALID_WIDGET_ORDER');
        $this->putJson("/api/dashboards/{$dashboard->id}/widgets-order",
            ['order' => $widgets->reverse()->pluck('id')->all()])->assertNoContent();
        $this->assertSame($widgets->last()->id, Dashboard::query()->findOrFail($dashboard->id)->widgets()->orderBy('sort_order')->value('id'));

        $this->deleteJson("/api/dashboards/{$dashboard->id}/widgets/{$created['id']}")->assertNoContent();
        $this->assertSame(1, Dashboard::query()->findOrFail($dashboard->id)->widgets()->count());
    }

    public function test_widget_data_needs_the_widget_permission_and_the_members_scope(): void
    {
        $masters = $this->createMasterFixtures($this->orgA, 'A');
        $this->createAssetFixture($this->orgA, $masters, 'A1');
        $this->createAssetFixture($this->orgA, $masters, 'A2');
        $this->actingAs($this->adminA);
        $dashboard = $this->customDashboard($this->adminA, $this->orgA, 'organization');
        $widget = $this->widgetOn($dashboard, 'total_assets');

        // total_assets needs asset.view: the plain viewer is refused even though the
        // dashboard itself is shared with them.
        $this->share($this->orgA, $dashboard->id, ['user_id' => $this->plainViewer->id], 'view');
        $this->actingAs($this->plainViewer);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}/data"), 403, 'WIDGET_FORBIDDEN');

        // The asset viewer gets the number, computed inside their (organization-wide) scope.
        $this->share($this->orgA, $dashboard->id, ['user_id' => $this->assetViewer->id], 'view');
        $this->actingAs($this->assetViewer);
        $payload = $this->getJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}/data")->assertOk()->json('data');
        $this->assertSame(2, $payload['value']);

        // A widget needing a permission nobody here holds (audit.view) is denied too.
        $auditWidget = $this->widgetOn($dashboard, 'asset_change_history');
        $this->assertErrorCode($this->getJson("/api/dashboards/{$dashboard->id}/widgets/{$auditWidget->id}/data"), 403, 'WIDGET_FORBIDDEN');

        // Another organization's dashboard is not reachable at all.
        $this->actingAs($this->adminB);
        $foreign = $this->customDashboard($this->adminB, $this->orgB);
        $this->actingAs($this->assetViewer);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$foreign->id}/widgets/{$widget->id}/data"), 404, 'NOT_FOUND');
    }

    public function test_every_catalog_widget_answers_with_data(): void
    {
        // Regression: assets_lost_damaged answered 500 (wrong builder type) and only E2E noticed.
        $masters = $this->createMasterFixtures($this->orgA, 'A');
        $this->createAssetFixture($this->orgA, $masters, 'A1');
        $this->actingAs($this->adminA);
        $dashboard = $this->customDashboard($this->adminA, $this->orgA, 'organization');

        $keys = WidgetCatalog::query()->where('is_active', true)->orderBy('widget_key')->pluck('widget_key');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $widget = $this->widgetOn($dashboard, $key);
            $response = $this->getJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}/data");
            $this->assertSame(200, $response->status(), "{$key}: ".$response->getContent());
        }
    }

    public function test_asset_board_is_provisioned_from_the_role_template(): void
    {
        // Organization template mapped to the DASH_VIEW role.
        $this->actingAs($this->adminA);
        $template = $this->postJson('/api/dashboard-templates', [
            'name' => 'Papan Aset Viewer', 'dashboard_type' => 'asset_board',
            'role_id' => $this->roleId($this->orgA, 'DASH_VIEW'),
        ])->assertCreated()->json('data');

        // A member of that role gets a personal asset board filled from the template on first open.
        $this->actingAs($this->plainViewer);
        $board = $this->getJson('/api/dashboards/asset-board')->assertOk()->json('data');
        $this->assertSame('asset_board', $board['dashboard_type']);
        $this->assertSame('personal', $board['visibility']);
        $this->assertSame($this->plainViewer->id, $board['created_by']['id'] ?? null);
        $this->assertSame($template['id'], $board['template']['id'] ?? null);

        // Second open returns the same board.
        $this->assertSame($board['id'], $this->getJson('/api/dashboards/asset-board')->json('data.id'));
        $this->assertSame(1, Dashboard::query()->where('dashboard_type', 'asset_board')->count());
    }

    public function test_a_new_organization_maps_the_built_in_roles_to_their_asset_board_templates(): void
    {
        // Provisioning copied the three system templates and mapped each to its built-in role (D-21).
        $expected = ['SUPER_ADMIN' => 'asset_board_super_admin', 'SUPERVISOR' => 'asset_board_supervisor', 'ADMIN' => 'asset_board_admin'];
        foreach ($expected as $roleCode => $templateKey) {
            $user = $this->createTenantUser($this->orgA, [$roleCode]);
            $this->actingAs($user);
            $board = $this->getJson('/api/dashboards/asset-board')->assertOk()->json('data');

            $template = DB::table('dashboard_templates')->where('id', $board['template']['id'] ?? null)->first();
            $this->assertNotNull($template, $roleCode);
            $this->assertSame($this->orgA->id, $template->organization_id, $roleCode);
            $this->assertSame($templateKey, $template->template_key, $roleCode);
            $this->assertSame($this->roleId($this->orgA, $roleCode), $template->role_id, $roleCode);

            // The member's copy carries the template's widgets, in order.
            $expectedWidgets = DB::table('template_widgets')->join('widget_catalog as c', 'c.id', '=', 'template_widgets.widget_catalog_id')
                ->where('template_widgets.template_id', $template->id)->orderBy('template_widgets.sort_order')->pluck('c.widget_key')->all();
            $this->assertNotEmpty($expectedWidgets, $roleCode);
            $this->assertSame($expectedWidgets, collect($board['widgets'])->sortBy('sort_order')->pluck('widget_key')->values()->all(), $roleCode);
        }
    }

    public function test_role_mapping_is_only_for_asset_boards_and_unique_per_role(): void
    {
        $this->actingAs($this->adminA);
        $roleId = $this->roleId($this->orgA, 'DASH_VIEW');

        // Overview template cannot carry a role mapping.
        $this->assertErrorCode($this->postJson('/api/dashboard-templates', [
            'name' => 'Overview Role', 'dashboard_type' => 'overview', 'role_id' => $roleId,
        ]), 422, 'INVALID_ROLE_MAPPING');

        $first = $this->postJson('/api/dashboard-templates', [
            'name' => 'Board Satu', 'dashboard_type' => 'asset_board', 'role_id' => $roleId,
        ])->assertCreated()->json('data');
        // A second active template for the same role is refused.
        $this->assertErrorCode($this->postJson('/api/dashboard-templates', [
            'name' => 'Board Dua', 'dashboard_type' => 'asset_board', 'role_id' => $roleId,
        ]), 422, 'ROLE_TEMPLATE_EXISTS');
        // Deactivating the first frees the role.
        $this->patchJson("/api/dashboard-templates/{$first['id']}", ['is_active' => false])->assertOk();
        $this->postJson('/api/dashboard-templates', [
            'name' => 'Board Dua', 'dashboard_type' => 'asset_board', 'role_id' => $roleId,
        ])->assertCreated();
    }

    // ------------------------------------------------------------------ helpers

    private function customDashboard(User $owner, Organization $organization, string $visibility = 'personal'): Dashboard
    {
        $dashboard = new Dashboard([
            'name' => 'Dashboard Uji '.fake()->unique()->numberBetween(1, 99999),
            'visibility' => $visibility,
            'status' => Dashboard::STATUS_ACTIVE,
        ]);
        $dashboard->organization_id = $organization->id;
        $dashboard->dashboard_type = 'custom';
        $dashboard->created_by = $owner->id;
        $dashboard->save();

        return $dashboard;
    }

    private function widgetOn(Dashboard $dashboard, string $widgetKey): DashboardWidget
    {
        $catalog = WidgetCatalog::query()->where('widget_key', $widgetKey)->firstOrFail();
        $widget = new DashboardWidget;
        $widget->forceFill([
            'organization_id' => $dashboard->organization_id,
            'dashboard_id' => $dashboard->id,
            'widget_catalog_id' => $catalog->id,
            'configuration' => [],
            'layout' => $catalog->default_layout,
            'sort_order' => 0,
            'is_visible' => true,
        ])->save();

        return $widget;
    }

    /** @param array{user_id?: string, role_id?: string} $target */
    private function share(Organization $organization, string $dashboardId, array $target, string $permission): string
    {
        $id = (string) Str::uuid7();
        DB::table('dashboard_shares')->insert(['id' => $id, 'organization_id' => $organization->id, 'dashboard_id' => $dashboardId,
            'user_id' => $target['user_id'] ?? null, 'role_id' => $target['role_id'] ?? null, 'permission' => $permission,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
