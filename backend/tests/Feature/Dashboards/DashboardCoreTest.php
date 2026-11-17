<?php

namespace Tests\Feature\Dashboards;

use App\Domain\Dashboards\Catalog\SystemTemplates;
use App\Domain\Dashboards\Catalog\SystemWidgets;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 of the Dashboard Builder: Overview and category dashboards created on first use
 * from the resolved template, custom dashboards, settings, soft deletion, explicit template
 * updates, organization templates and each member's default dashboard — with object-level
 * access (owner, dashboard.manage, shares) on top of dashboard.view.
 */
class DashboardCoreTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    /** dashboard.view only. */
    private User $viewerA;

    /** dashboard.view + dashboard.create (personal dashboards). */
    private User $creatorA;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        $this->createRole($this->orgA, 'DASH_VIEW', ['dashboard.view']);
        $this->createRole($this->orgA, 'DASH_CREATE', ['dashboard.view', 'dashboard.create']);
        $this->viewerA = $this->createTenantUser($this->orgA, ['DASH_VIEW']);
        $this->creatorA = $this->createTenantUser($this->orgA, ['DASH_CREATE']);
    }

    public function test_overview_is_created_once_from_the_system_template_and_is_the_default(): void
    {
        $this->as($this->viewerA);
        $nav = $this->getJson('/api/dashboards/navigation')->assertOk()->json('data');
        $overview = $nav['overview'];
        $this->assertSame('overview', $overview['dashboard_type']);
        $this->assertTrue($overview['is_default']);
        $this->assertSame($overview['id'], $nav['default_dashboard_id']);
        $this->assertSame('view', $overview['access']);
        $this->assertFalse($overview['can']['manage']);

        $detail = $this->getJson('/api/dashboards/overview')->assertOk()->json('data');
        $this->assertSame($overview['id'], $detail['id']);
        $this->assertSame(array_column(SystemTemplates::all()['overview']['widgets'], 'widget'), array_column($detail['widgets'], 'widget_key'));
        $this->assertSame(range(0, count($detail['widgets']) - 1), array_column($detail['widgets'], 'sort_order'));
        $this->assertEquals(['w' => 6, 'h' => 2], $detail['widgets'][array_search('recent_assets', array_column($detail['widgets'], 'widget_key'))]['layout']);
        $this->assertSame('Overview', $detail['template']['name']);
        $this->assertSame(1, $detail['template_version']);
        $this->assertFalse($detail['template_update_available']);

        // Idempotent: every later visit finds the same row.
        $this->getJson('/api/dashboards/default')->assertOk()->assertJsonPath('data.id', $overview['id']);
        $this->assertSame(1, Dashboard::query()->where('organization_id', $this->orgA->id)->where('dashboard_type', 'overview')->count());

        // Never deleted, never archived (managers neither).
        $this->as($this->adminA);
        $this->assertErrorCode($this->deleteJson("/api/dashboards/{$overview['id']}"), 422, 'DASHBOARD_NOT_DELETABLE');
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$overview['id']}", ['status' => 'archived']), 422, 'DASHBOARD_NOT_ARCHIVABLE');
        $this->patchJson("/api/dashboards/{$overview['id']}", ['name' => 'Ringkasan Alpha'])->assertOk()->assertJsonPath('data.name', 'Ringkasan Alpha');
    }

    public function test_category_dashboards_are_created_on_first_access_from_the_matching_template(): void
    {
        $it = $this->category($this->orgA, 'AST_IT', 'PERANGKAT_IT');
        $warranty = $this->attribute($this->orgA, $it, 'tgl_garansi_alpha', 'Tanggal Garansi Berakhir');
        $laptop = $this->category($this->orgA, 'LAPTOP', null, $it);
        $other = $this->category($this->orgA, 'FURNITUR');

        $this->as($this->viewerA);
        $nav = $this->getJson('/api/dashboards/navigation')->assertOk()->json('data.categories');
        $this->assertEqualsCanonicalizing([$it->id, $laptop->id, $other->id], array_column($nav, 'category_id'));
        $this->assertSame([null, null, null], array_column($nav, 'dashboard_id'), 'nothing is created before a category dashboard is opened');

        $itDashboard = $this->getJson("/api/dashboards/categories/{$it->id}")->assertOk()->json('data');
        $this->assertSame('category', $itDashboard['dashboard_type']);
        $this->assertSame($it->id, $itDashboard['category']['id']);
        $this->assertSame('Aset IT & Elektronik', $itDashboard['template']['name']);
        $reminder = collect($itDashboard['widgets'])->firstWhere('widget_key', 'date_reminders');
        // The template's attribute label became this organization's attribute key.
        $this->assertEquals(['attribute' => $warranty->key, 'days_ahead' => 30], $reminder['configuration']);
        $this->assertSame($itDashboard['id'], $this->getJson("/api/dashboards/categories/{$it->id}")->json('data.id'));

        // A subcategory inherits its ancestor's template and fields; others use the generic template.
        $laptopDashboard = $this->getJson("/api/dashboards/categories/{$laptop->id}")->assertOk()->json('data');
        $this->assertSame('Aset IT & Elektronik', $laptopDashboard['template']['name']);
        $this->assertSame($warranty->key, collect($laptopDashboard['widgets'])->firstWhere('widget_key', 'date_reminders')['configuration']['attribute']);
        $this->getJson("/api/dashboards/categories/{$other->id}")->assertOk()->assertJsonPath('data.template.name', 'Kategori Umum');

        $this->assertSame(1, Dashboard::query()->where('category_id', $it->id)->count());
        $this->assertNotNull(collect($this->getJson('/api/dashboards/navigation')->json('data.categories'))->firstWhere('category_id', $it->id)['dashboard_id']);
    }

    public function test_an_organization_default_template_wins_over_the_system_one(): void
    {
        $this->as($this->adminA);
        $system = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'category_it')->value('id');
        $copy = $this->postJson('/api/dashboard-templates', [
            'name' => 'IT Alpha', 'dashboard_type' => 'category', 'category_type_key' => 'AST_IT',
            'is_default' => true, 'source_template_id' => $system,
        ])->assertCreated()->json('data');
        $this->assertSame('organization', $copy['scope']);
        $this->assertTrue($copy['is_editable']);
        $this->assertCount(count(SystemTemplates::all()['category_it']['widgets']), $copy['widgets']);

        $it = $this->category($this->orgA, 'AST_IT', 'PERANGKAT_IT');
        $this->getJson("/api/dashboards/categories/{$it->id}")->assertOk()->assertJsonPath('data.template.id', $copy['id']);

        // Another organization still gets the system template.
        $itB = $this->category($this->orgB, 'AST_IT', 'PERANGKAT_IT');
        $this->as($this->adminB);
        $this->getJson("/api/dashboards/categories/{$itB->id}")->assertOk()->assertJsonPath('data.template.id', $system);
    }

    public function test_archived_and_foreign_categories_get_no_dashboard(): void
    {
        $archived = $this->category($this->orgA, 'LAMA');
        DB::table('tenant_asset_categories')->where('id', $archived->id)->update(['status' => 'archived']);
        $foreign = $this->category($this->orgB, 'ASING');

        $this->as($this->adminA);
        $this->assertErrorCode($this->getJson("/api/dashboards/categories/{$archived->id}"), 422, 'CATEGORY_ARCHIVED');
        $this->assertErrorCode($this->getJson("/api/dashboards/categories/{$foreign->id}"), 404, 'NOT_FOUND');
        $this->assertSame(0, Dashboard::query()->where('dashboard_type', 'category')->count());
    }

    public function test_personal_dashboards_need_dashboard_create_and_stay_with_their_owner(): void
    {
        $template = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'custom_operations')->value('id');

        $this->as($this->viewerA);
        $this->assertErrorCode($this->postJson('/api/dashboards', ['name' => 'Milik Saya', 'visibility' => 'personal']), 403, 'FORBIDDEN');

        $this->as($this->creatorA);
        // Organization dashboards need dashboard.manage.
        $this->assertErrorCode($this->postJson('/api/dashboards', ['name' => 'Tim', 'visibility' => 'organization']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson('/api/dashboards', ['name' => 'X', 'visibility' => 'personal', 'template_id' => (string) Str::uuid7()]), 422, 'INVALID_TEMPLATE');
        $mine = $this->postJson('/api/dashboards', ['name' => 'Milik Saya', 'visibility' => 'personal', 'template_id' => $template])
            ->assertCreated()->json('data');
        $this->assertSame('custom', $mine['dashboard_type']);
        $this->assertSame('manage', $mine['access']);
        $this->assertTrue($mine['can']['delete']);
        $this->assertSame(array_column(SystemTemplates::all()['custom_operations']['widgets'], 'widget'), array_column($mine['widgets'], 'widget_key'));
        $empty = $this->postJson('/api/dashboards', ['name' => 'Kosong', 'visibility' => 'personal'])->assertCreated()->json('data');
        $this->assertSame([], $empty['widgets']);

        $nav = $this->getJson('/api/dashboards/navigation')->json('data');
        $this->assertEqualsCanonicalizing([$mine['id'], $empty['id']], array_column($nav['mine'], 'id'));
        $this->assertSame([], $nav['shared']);

        // Nobody else, not even the Super Admin, sees or changes it.
        $this->as($this->adminA);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$mine['id']}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$mine['id']}", ['name' => 'Diambil']), 404, 'NOT_FOUND');
        $this->assertNotContains($mine['id'], array_column($this->getJson('/api/dashboards')->json('data'), 'id'));
        // And it cannot be captured into a template by someone else.
        $this->assertErrorCode($this->postJson('/api/dashboard-templates', ['name' => 'Curian', 'dashboard_type' => 'custom', 'dashboard_id' => $mine['id']]), 422, 'INVALID_DASHBOARD');
    }

    public function test_organization_dashboards_are_reached_through_creator_manage_permission_or_shares(): void
    {
        $this->as($this->adminA);
        $team = $this->postJson('/api/dashboards', ['name' => 'Tim Operasional', 'visibility' => 'organization'])->assertCreated()->json('data');
        $creator = $this->createTenantUser($this->orgA, ['DASH_VIEW']);

        // Not shared: invisible to plain members.
        $this->as($this->viewerA);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$team['id']}"), 404, 'NOT_FOUND');
        $this->assertNotContains($team['id'], array_column($this->getJson('/api/dashboards')->json('data'), 'id'));

        // A view share: open it, but not change it.
        $share = $this->share($this->orgA, $team['id'], ['user_id' => $this->viewerA->id], 'view');
        $this->as($this->viewerA);
        $this->getJson("/api/dashboards/{$team['id']}")->assertOk()->assertJsonPath('data.access', 'view');
        $this->assertContains($team['id'], array_column($this->getJson('/api/dashboards/navigation')->json('data.shared'), 'id'));
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$team['id']}", ['name' => 'Ubah']), 403, 'FORBIDDEN');

        // Edit covers widgets (later phases), not settings; manage covers settings.
        DB::table('dashboard_shares')->where('id', $share)->update(['permission' => 'edit']);
        $this->getJson("/api/dashboards/{$team['id']}")->assertOk()->assertJsonPath('data.can.edit', true)->assertJsonPath('data.can.manage', false);
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$team['id']}", ['name' => 'Ubah']), 403, 'FORBIDDEN');
        DB::table('dashboard_shares')->where('id', $share)->update(['permission' => 'manage']);
        $this->patchJson("/api/dashboards/{$team['id']}", ['name' => 'Tim Ops'])->assertOk()->assertJsonPath('data.name', 'Tim Ops');
        // Organization-wide settings still need dashboard.manage.
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$team['id']}", ['is_default' => true]), 403, 'FORBIDDEN');

        // Shares to a role reach its members; the highest level counts.
        $this->share($this->orgA, $team['id'], ['role_id' => $this->roleId($this->orgA, 'DASH_VIEW')], 'view');
        $this->as($creator);
        $this->getJson("/api/dashboards/{$team['id']}")->assertOk()->assertJsonPath('data.access', 'view');
        $this->as($this->viewerA);
        $this->getJson("/api/dashboards/{$team['id']}")->assertOk()->assertJsonPath('data.access', 'manage');

        // Another organization never reaches it, whatever its permissions.
        $this->as($this->adminB);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$team['id']}"), 404, 'NOT_FOUND');
        $this->assertNotContains($team['id'], array_column($this->getJson('/api/dashboards')->json('data'), 'id'));
    }

    public function test_settings_visibility_default_and_soft_delete(): void
    {
        $this->as($this->creatorA);
        $mine = $this->postJson('/api/dashboards', ['name' => 'Pribadi', 'visibility' => 'personal'])->json('data');
        // Publishing to the organization is an organization-wide change.
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$mine['id']}", ['visibility' => 'organization']), 403, 'FORBIDDEN');

        $this->as($this->adminA);
        $overviewId = $this->getJson('/api/dashboards/overview')->json('data.id');
        $team = $this->postJson('/api/dashboards', ['name' => 'Tim', 'visibility' => 'organization'])->json('data');
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$team['id']}", ['visibility' => 'personal']), 422, 'VISIBILITY_LOCKED');
        $personal = $this->postJson('/api/dashboards', ['name' => 'Draf', 'visibility' => 'personal'])->json('data');
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$personal['id']}", ['is_default' => true]), 422, 'INVALID_DEFAULT_DASHBOARD');
        $this->patchJson("/api/dashboards/{$personal['id']}", ['visibility' => 'organization'])->assertOk()->assertJsonPath('data.visibility', 'organization');

        // One organization default at a time; it cannot be archived.
        $this->patchJson("/api/dashboards/{$team['id']}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(Dashboard::query()->findOrFail($overviewId)->is_default);
        $this->assertErrorCode($this->patchJson("/api/dashboards/{$team['id']}", ['status' => 'archived']), 422, 'DASHBOARD_NOT_ARCHIVABLE');
        $this->getJson('/api/dashboards/default')->assertJsonPath('data.id', $team['id']);
        // Members who cannot open the organization default land on the Overview.
        $this->as($this->viewerA);
        $this->getJson('/api/dashboards/default')->assertJsonPath('data.id', $overviewId);

        // Soft delete: gone from every listing, the row stays; the default falls back.
        $this->as($this->adminA);
        $this->deleteJson("/api/dashboards/{$team['id']}")->assertNoContent();
        $this->assertNotNull(Dashboard::withTrashed()->findOrFail($team['id'])->deleted_at);
        $this->assertErrorCode($this->getJson("/api/dashboards/{$team['id']}"), 404, 'NOT_FOUND');
        $this->assertNotContains($team['id'], array_column($this->getJson('/api/dashboards')->json('data'), 'id'));
        $this->getJson('/api/dashboards/default')->assertJsonPath('data.id', $overviewId);
        $this->assertContains('dashboard.deleted', DB::table('audit_logs')->where('entity_id', $team['id'])->pluck('action')->all());
    }

    public function test_default_dashboard_preference_must_be_accessible(): void
    {
        $this->as($this->creatorA);
        $mine = $this->postJson('/api/dashboards', ['name' => 'Pribadi', 'visibility' => 'personal'])->json('data');
        $this->as($this->adminA);
        $adminPersonal = $this->postJson('/api/dashboards', ['name' => 'Admin', 'visibility' => 'personal'])->json('data');

        $this->as($this->creatorA);
        $this->assertErrorCode($this->putJson('/api/dashboard-preferences', ['default_dashboard_id' => $adminPersonal['id']]), 422, 'INVALID_DEFAULT_DASHBOARD');
        $this->assertErrorCode($this->putJson('/api/dashboard-preferences', ['sidebar_preferences' => ['script' => 'alert(1)']]), 422, 'VALIDATION_FAILED');
        $this->putJson('/api/dashboard-preferences', [
            'default_dashboard_id' => $mine['id'], 'sidebar_preferences' => ['collapsed_sections' => ['categories']],
        ])->assertOk()->assertJsonPath('data.default_dashboard_id', $mine['id'])
            ->assertJsonPath('data.effective_default_dashboard_id', $mine['id'])
            ->assertJsonPath('data.sidebar_preferences.collapsed_sections', ['categories']);
        $this->getJson('/api/dashboards/default')->assertJsonPath('data.id', $mine['id']);
        $this->getJson('/api/dashboards/navigation')->assertJsonPath('data.default_dashboard_id', $mine['id']);

        // Preferences are each member's own.
        $this->as($this->viewerA);
        $this->getJson('/api/dashboard-preferences')->assertOk()->assertJsonPath('data.default_dashboard_id', null);

        // A deleted choice falls back to the Overview.
        $this->as($this->creatorA);
        $this->deleteJson("/api/dashboards/{$mine['id']}")->assertNoContent();
        $overview = Dashboard::query()->where('organization_id', $this->orgA->id)->where('dashboard_type', 'overview')->value('id');
        $this->getJson('/api/dashboard-preferences')->assertJsonPath('data.effective_default_dashboard_id', $overview);
    }

    public function test_templates_system_ones_are_read_only_and_updates_are_applied_explicitly(): void
    {
        $this->as($this->adminA);
        $system = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'custom_operations')->value('id');
        $beta = (new DashboardTemplate)->forceFill(['organization_id' => $this->orgB->id, 'template_key' => 'beta', 'name' => 'Beta',
            'dashboard_type' => 'custom', 'scope' => 'organization']);
        $beta->save();

        $listed = array_column($this->getJson('/api/dashboard-templates')->assertOk()->json('data'), 'id');
        $this->assertContains($system, $listed);
        $this->assertNotContains($beta->id, $listed);
        $this->assertErrorCode($this->getJson("/api/dashboard-templates/{$beta->id}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->patchJson("/api/dashboard-templates/{$system}", ['name' => 'Diubah']), 403, 'SYSTEM_TEMPLATE_READ_ONLY');
        $this->assertErrorCode($this->deleteJson("/api/dashboard-templates/{$system}"), 403, 'SYSTEM_TEMPLATE_READ_ONLY');
        $this->assertErrorCode($this->postJson('/api/dashboard-templates', ['name' => 'Salah', 'dashboard_type' => 'custom', 'category_type_key' => 'AST_IT']), 422, 'INVALID_CATEGORY_TYPE_KEY');

        // Capture a dashboard into an organization template.
        $source = $this->postJson('/api/dashboards', ['name' => 'Sumber', 'visibility' => 'organization', 'template_id' => $system])->json('data');
        $template = $this->postJson('/api/dashboard-templates', ['name' => 'Ops Alpha', 'dashboard_type' => 'custom', 'dashboard_id' => $source['id']])
            ->assertCreated()->json('data');
        $this->assertSame(1, $template['version']);
        $this->assertSame(array_column($source['widgets'], 'widget_key'), array_column($template['widgets'], 'widget_key'));

        $made = $this->postJson('/api/dashboards', ['name' => 'Dari Template', 'visibility' => 'organization', 'template_id' => $template['id']])->json('data');
        $this->assertSame(1, $made['template_version']);

        // A new template version leaves existing dashboards alone until the update is applied.
        $smaller = $this->postJson('/api/dashboards', ['name' => 'Kecil', 'visibility' => 'personal'])->json('data');
        $this->patchJson("/api/dashboard-templates/{$template['id']}", ['dashboard_id' => $smaller['id']])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.widgets', []);
        $stale = $this->getJson("/api/dashboards/{$made['id']}")->json('data');
        $this->assertTrue($stale['template_update_available']);
        $this->assertCount(count($source['widgets']), $stale['widgets']);

        $updated = $this->postJson("/api/dashboards/{$made['id']}/apply-template")->assertOk()->json('data');
        $this->assertSame(2, $updated['template_version']);
        $this->assertFalse($updated['template_update_available']);
        $this->assertSame([], $updated['widgets']);

        // System dashboards only take templates of their own kind.
        $overview = $this->getJson('/api/dashboards/overview')->json('data.id');
        $this->assertErrorCode($this->postJson("/api/dashboards/{$overview}/apply-template", ['template_id' => $template['id']]), 422, 'INVALID_TEMPLATE');
        $this->postJson("/api/dashboards/{$overview}/apply-template")->assertOk()->assertJsonPath('data.template.name', 'Overview');

        // Deleting the template keeps the dashboards made from it.
        $this->deleteJson("/api/dashboard-templates/{$template['id']}")->assertNoContent();
        $this->getJson("/api/dashboards/{$made['id']}")->assertOk()->assertJsonPath('data.template', null);

        // Only dashboard.manage writes templates; viewers read them.
        $this->as($this->creatorA);
        $this->getJson('/api/dashboard-templates')->assertOk();
        $this->assertErrorCode($this->postJson('/api/dashboard-templates', ['name' => 'Saya', 'dashboard_type' => 'custom']), 403, 'FORBIDDEN');
    }

    public function test_one_default_template_per_kind_and_capture_turns_attribute_keys_into_labels(): void
    {
        $it = $this->category($this->orgA, 'AST_IT', 'PERANGKAT_IT');
        $warranty = $this->attribute($this->orgA, $it, 'tgl_garansi_alpha', 'Tanggal Garansi Berakhir');

        $this->as($this->adminA);
        $dashboard = $this->getJson("/api/dashboards/categories/{$it->id}")->json('data');
        $first = $this->postJson('/api/dashboard-templates', ['name' => 'IT 1', 'dashboard_type' => 'category', 'category_type_key' => 'AST_IT',
            'is_default' => true, 'dashboard_id' => $dashboard['id']])->assertCreated()->json('data');
        $reminder = collect($first['widgets'])->firstWhere('widget_key', 'date_reminders');
        // Portable: the organization's key went back to the label.
        $this->assertEquals(['attribute_label' => $warranty->label, 'days_ahead' => 30], $reminder['configuration']);

        $second = $this->postJson('/api/dashboard-templates', ['name' => 'IT 2', 'dashboard_type' => 'category', 'category_type_key' => 'AST_IT', 'is_default' => true])
            ->assertCreated()->json('data');
        $this->assertTrue($second['is_default']);
        $this->getJson("/api/dashboard-templates/{$first['id']}")->assertJsonPath('data.is_default', false);
        $this->assertErrorCode($this->patchJson("/api/dashboard-templates/{$first['id']}", ['is_default' => true, 'is_active' => false]), 422, 'TEMPLATE_INACTIVE');
        $this->patchJson("/api/dashboard-templates/{$second['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_default', false);
        $this->assertNotContains($second['id'], array_column($this->getJson('/api/dashboard-templates')->json('data'), 'id'));
        $this->assertContains($second['id'], array_column($this->getJson('/api/dashboard-templates?include_inactive=1')->json('data'), 'id'));
    }

    public function test_widget_catalog_lists_active_widgets_with_their_schema(): void
    {
        $this->as($this->viewerA);
        $catalog = $this->getJson('/api/widget-catalog')->assertOk()->json('data');
        $this->assertSame(array_keys(SystemWidgets::all()), array_column($catalog, 'widget_key'));
        $total = collect($catalog)->firstWhere('widget_key', 'total_assets');
        $this->assertSame('statistic', $total['component_type']);
        $this->assertSame('category_ids', $total['configuration_schema']['fields']['category_ids']['type']);

        // Without dashboard.view nothing of the Dashboard Builder is reachable.
        $none = $this->createTenantUser($this->orgA, [$this->viewerRole($this->orgA)]);
        $this->as($none);
        foreach (['/api/widget-catalog', '/api/dashboards', '/api/dashboards/navigation', '/api/dashboard-templates', '/api/dashboard-preferences'] as $uri) {
            $this->assertErrorCode($this->getJson($uri), 403, 'FORBIDDEN');
        }
    }

    public function test_each_organization_has_its_own_overview_and_lists(): void
    {
        $this->as($this->adminA);
        $overviewA = $this->getJson('/api/dashboards/overview')->json('data.id');
        $this->as($this->adminB);
        $overviewB = $this->getJson('/api/dashboards/overview')->json('data.id');
        $this->assertNotSame($overviewA, $overviewB);
        $this->assertSame([$overviewB], array_column($this->getJson('/api/dashboards')->json('data'), 'id'));
        $this->assertErrorCode($this->getJson("/api/dashboards/{$overviewA}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/dashboards/{$overviewA}/apply-template"), 404, 'NOT_FOUND');
    }

    public function test_listing_runs_a_fixed_number_of_queries_however_many_dashboards_there_are(): void
    {
        $role = $this->roleId($this->orgA, 'DASH_VIEW');
        $make = function (int $count) use ($role) {
            for ($i = 0; $i < $count; $i++) {
                $id = DB::table('dashboards')->insertGetId(['id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id,
                    'created_by' => $this->adminA->id, 'name' => 'Tim '.Str::random(6), 'dashboard_type' => 'custom', 'visibility' => 'organization',
                    'created_at' => now(), 'updated_at' => now()]);
                $this->share($this->orgA, $id, ['role_id' => $role], 'view');
            }
        };
        $queries = function (string $uri): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->as($this->viewerA);
        $make(2);
        $this->getJson('/api/dashboards/navigation')->assertOk();
        $navigation = $queries('/api/dashboards/navigation');
        $index = $queries('/api/dashboards');
        $make(8);
        $this->assertCount(10, $this->getJson('/api/dashboards/navigation')->json('data.shared'));
        $this->assertSame($navigation, $queries('/api/dashboards/navigation'));
        $this->assertSame($index, $queries('/api/dashboards'));
    }

    private function as(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'web');
    }

    private function category(Organization $organization, string $code, ?string $generic = null, ?TenantAssetCategory $parent = null): TenantAssetCategory
    {
        $genericId = GenericAssetCategory::query()->where('code', $generic ?? 'UMUM')->value('id')
            ?? GenericAssetCategory::query()->where('code', 'UMUM')->value('id');
        $category = (new TenantAssetCategory)->forceFill(['organization_id' => $organization->id, 'code' => $code, 'name' => "Kategori {$code}",
            'generic_category_id' => $parent?->generic_category_id ?? $genericId, 'parent_id' => $parent?->id]);
        $category->save();

        return $category;
    }

    private function attribute(Organization $organization, TenantAssetCategory $category, string $key, string $label): TenantCategoryAttribute
    {
        $attribute = (new TenantCategoryAttribute)->forceFill(['organization_id' => $organization->id, 'category_id' => $category->id,
            'key' => $key, 'label' => $label, 'data_type' => 'date']);
        $attribute->save();

        return $attribute;
    }

    /** @param  array{user_id?: string, role_id?: string}  $target */
    private function share(Organization $organization, string $dashboardId, array $target, string $permission): string
    {
        $id = (string) Str::uuid7();
        DB::table('dashboard_shares')->insert(['id' => $id, 'organization_id' => $organization->id, 'dashboard_id' => $dashboardId,
            'user_id' => $target['user_id'] ?? null, 'role_id' => $target['role_id'] ?? null, 'permission' => $permission,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
