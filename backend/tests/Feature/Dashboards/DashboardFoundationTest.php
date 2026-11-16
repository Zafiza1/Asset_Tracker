<?php

namespace Tests\Feature\Dashboards;

use App\Domain\Dashboards\Catalog\SystemTemplates;
use App\Domain\Dashboards\Catalog\SystemWidgets;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Phase 1 of the Dashboard Builder: the database keeps dashboards, widgets, shares, templates
 * and preferences inside their organization (composite FKs, triggers, forced RLS), personal
 * dashboards and preferences with their owner, and system templates read-only for tenants.
 */
class DashboardFoundationTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private TenantAssetCategory $categoryA;

    private TenantAssetCategory $categoryB;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        $this->categoryA = $this->category($this->orgA, 'CAT_A');
        $this->categoryB = $this->category($this->orgB, 'CAT_B');
    }

    public function test_catalog_and_system_templates_are_seeded_as_json_objects(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(SystemWidgets::all()), DB::table('widget_catalog')->where('is_active', true)->pluck('widget_key')->all());
        $this->assertEqualsCanonicalizing(array_keys(SystemTemplates::all()),
            DB::table('dashboard_templates')->whereNull('organization_id')->where('scope', 'system')->pluck('template_key')->all());

        foreach (SystemTemplates::all() as $key => $spec) {
            $template = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', $key)->firstOrFail();
            $this->assertSame(array_column($spec['widgets'], 'widget'), $template->widgets()->with('catalog')->get()->pluck('catalog.widget_key')->all(), $key);
        }
        $this->assertSame(0, DB::table('template_widgets')->whereNotNull('organization_id')->count());
        // Empty configurations are {} (CHECK jsonb_typeof = 'object'), not [].
        $this->assertSame(0, DB::table('template_widgets')->whereRaw("jsonb_typeof(configuration) <> 'object' OR jsonb_typeof(layout) <> 'object'")->count());
        $layout = DB::table('template_widgets')->join('widget_catalog as c', 'c.id', '=', 'template_widgets.widget_catalog_id')
            ->where('c.widget_key', 'recent_assets')->value('layout');
        $this->assertEquals(['w' => 6, 'h' => 2], json_decode($layout, true));
    }

    public function test_syncing_templates_again_changes_nothing_while_the_version_is_the_same(): void
    {
        $overview = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'overview')->firstOrFail();
        $dashboard = $this->insertDashboard($this->orgA, ['dashboard_type' => 'overview', 'template_id' => $overview->id, 'template_version' => 1]);
        $this->insertWidget($this->orgA, $dashboard);
        $before = DB::table('template_widgets')->orderBy('id')->pluck('id')->all();

        SystemTemplates::sync();

        // Widgets are only replaced when a template's version goes up; dashboards keep their own copies either way.
        $this->assertSame($before, DB::table('template_widgets')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, DB::table('dashboard_widgets')->where('dashboard_id', $dashboard)->count());
    }

    public function test_tenants_only_read_the_widget_catalog(): void
    {
        $widget = DB::table('widget_catalog')->value('id');
        $this->asTenant($this->orgA, $this->adminA, function () use ($widget) {
            $this->assertGreaterThan(0, DB::table('widget_catalog')->count());
            $this->expectQueryError('42501', fn () => DB::table('widget_catalog')->where('id', $widget)->update(['name' => 'Hacked']));
            $this->expectQueryError('42501', fn () => DB::table('widget_catalog')->insert(['id' => (string) Str::uuid7(), 'widget_key' => 'raw_sql',
                'name' => 'x', 'component_type' => 'table', 'created_at' => now(), 'updated_at' => now()]));
        });
        // Only the system context (the seeder) writes it.
        SystemWidgets::sync();
        $this->assertSame(count(SystemWidgets::all()), DB::table('widget_catalog')->count());
    }

    public function test_tenants_read_system_and_own_templates_but_cannot_change_system_ones(): void
    {
        $system = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'overview')->value('id');
        $ownB = $this->insertTemplate($this->orgB, 'beta_template');

        $this->asTenant($this->orgA, $this->adminA, function () use ($system, $ownB) {
            $template = new DashboardTemplate(['template_key' => 'alpha_template', 'name' => 'Alpha', 'dashboard_type' => 'custom']);
            $template->forceFill(['scope' => DashboardTemplate::SCOPE_ORGANIZATION])->save();
            $this->assertSame($this->orgA->id, $template->organization_id);

            $visible = DashboardTemplate::query()->pluck('id');
            $this->assertContains($system, $visible);
            $this->assertContains($template->id, $visible);
            $this->assertNotContains($ownB, $visible);
            $this->assertSame(0, DB::table('dashboard_templates')->where('id', $ownB)->count(), 'RLS hides other organizations\' templates');

            // System rows: readable, never writable from a tenant (RLS filters updates and deletes, rejects inserts).
            $this->assertSame(0, DB::table('dashboard_templates')->where('id', $system)->update(['name' => 'Hacked']));
            $this->assertSame(0, DB::table('template_widgets')->where('template_id', $system)->delete());
            $this->expectQueryError('42501', fn () => DB::table('dashboard_templates')->insert($this->templateRow(null, 'fake_system')));
            // A widget of this organization cannot be attached to a system or foreign template.
            $catalog = DB::table('widget_catalog')->value('id');
            foreach ([$system, $ownB] as $foreign) {
                $this->expectQueryError('P0001', fn () => DB::table('template_widgets')->insert(['id' => (string) Str::uuid7(),
                    'organization_id' => $this->orgA->id, 'template_id' => $foreign, 'widget_catalog_id' => $catalog, 'created_at' => now(), 'updated_at' => now()]));
            }
        });
        $this->assertSame('Overview', DB::table('dashboard_templates')->where('id', $system)->value('name'));
    }

    public function test_template_scope_and_keys_are_consistent(): void
    {
        // Organization template without an organization, system template with one, category key on a custom template.
        $this->expectQueryError('23514', fn () => DB::table('dashboard_templates')->insert(['scope' => 'organization'] + $this->templateRow(null, 'x1')));
        $this->expectQueryError('23514', fn () => DB::table('dashboard_templates')->insert(['scope' => 'system'] + $this->templateRow($this->orgA, 'x2')));
        $this->expectQueryError('23514', fn () => DB::table('dashboard_templates')->insert(['category_type_key' => 'AST_IT'] + $this->templateRow($this->orgA, 'x3')));
        // Keys are unique per owner; one active default per owner, dashboard type and category type.
        $this->insertTemplate($this->orgA, 'dup');
        $this->expectQueryError('23505', fn () => DB::table('dashboard_templates')->insert($this->templateRow($this->orgA, 'dup')));
        $this->insertTemplate($this->orgB, 'dup');
        $this->insertTemplate($this->orgA, 'default_1', ['is_default' => true, 'dashboard_type' => 'category', 'category_type_key' => 'AST_IT']);
        $this->expectQueryError('23505', fn () => DB::table('dashboard_templates')->insert(
            ['is_default' => true, 'dashboard_type' => 'category', 'category_type_key' => 'AST_IT'] + $this->templateRow($this->orgA, 'default_2')));
        // The creator of an organization template is a member of that organization.
        $this->expectQueryError('23503', fn () => DB::table('dashboard_templates')->insert(['created_by' => $this->adminB->id] + $this->templateRow($this->orgA, 'x4')));
    }

    public function test_dashboards_cannot_reference_another_organizations_category_member_or_template(): void
    {
        $templateB = $this->insertTemplate($this->orgB, 'beta_custom');

        $this->expectQueryError('23503', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryB->id]));
        $this->expectQueryError('23503', fn () => $this->insertDashboard($this->orgA, ['visibility' => 'personal', 'created_by' => $this->adminB->id]));
        $this->expectQueryError('P0001', fn () => $this->insertDashboard($this->orgA, ['template_id' => $templateB]));

        $dashboardB = $this->insertDashboard($this->orgB);
        $this->expectQueryError('23503', fn () => $this->insertWidget($this->orgA, $dashboardB));
        $this->expectQueryError('23503', fn () => $this->insertShare($this->orgA, $dashboardB, ['user_id' => $this->adminA->id]));

        // Own category, member and template, and system templates, are fine.
        $system = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', 'category_generic')->value('id');
        $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryA->id, 'template_id' => $system]);
        $this->insertDashboard($this->orgB, ['visibility' => 'personal', 'created_by' => $this->adminB->id, 'template_id' => $templateB]);
    }

    public function test_dashboard_shape_rules(): void
    {
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'category']));
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['category_id' => $this->categoryA->id]));
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'overview', 'visibility' => 'personal', 'created_by' => $this->adminA->id]));
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['visibility' => 'personal']));
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['visibility' => 'personal', 'created_by' => $this->adminA->id, 'is_default' => true]));
        $this->expectQueryError('23514', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'report']));

        // One Overview per organization; it can never be deleted.
        $overview = $this->insertDashboard($this->orgA, ['dashboard_type' => 'overview', 'is_default' => true]);
        $this->expectQueryError('23505', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'overview']));
        $this->insertDashboard($this->orgB, ['dashboard_type' => 'overview']);
        $this->expectQueryError('23514', fn () => DB::table('dashboards')->where('id', $overview)->update(['deleted_at' => now()]));
        // Dashboards are soft-deleted: the runtime role has no DELETE at all (and a trigger keeps the Overview from the owner too).
        $this->expectQueryError('42501', fn () => DB::table('dashboards')->where('id', $overview)->delete());
        $this->expectQueryError('P0001', fn () => DB::table('dashboards')->where('id', $overview)->update(['dashboard_type' => 'custom']));
        // One default per organization.
        $this->expectQueryError('23505', fn () => $this->insertDashboard($this->orgA, ['is_default' => true]));

        // One live dashboard per category; a soft-deleted one makes room for a new one.
        $category = $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryA->id]);
        $this->expectQueryError('23505', fn () => $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryA->id]));
        $this->expectQueryError('P0001', fn () => DB::table('dashboards')->where('id', $category)->update(['category_id' => null, 'dashboard_type' => 'custom']));
        DB::table('dashboards')->where('id', $category)->update(['deleted_at' => now()]);
        $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryA->id]);
    }

    public function test_personal_dashboards_and_their_widgets_are_visible_to_their_owner_only(): void
    {
        $owner = $this->createTenantUser($this->orgA, ['ADMIN']);
        $personal = $this->insertDashboard($this->orgA, ['visibility' => 'personal', 'created_by' => $owner->id]);
        $this->insertWidget($this->orgA, $personal);
        $shared = $this->insertDashboard($this->orgA);
        $this->insertWidget($this->orgA, $shared);

        $this->asTenant($this->orgA, $owner, function () use ($personal, $shared) {
            $this->assertEqualsCanonicalizing([$personal, $shared], Dashboard::query()->pluck('id')->all());
            $this->assertSame(2, DashboardWidget::query()->count());
            $this->assertSame(1, Dashboard::query()->whereKey($personal)->update(['name' => 'Milik saya']));
        });

        // Not even the organization's Super Admin sees (or changes) someone's personal dashboard.
        $this->asTenant($this->orgA, $this->adminA, function () use ($personal, $shared, $owner) {
            $this->assertSame([$shared], DB::table('dashboards')->pluck('id')->all());
            $this->assertSame(1, DB::table('dashboard_widgets')->count());
            $this->assertSame(0, DB::table('dashboards')->where('id', $personal)->update(['name' => 'Taken']));
            $this->assertSame(0, DB::table('dashboard_widgets')->where('dashboard_id', $personal)->delete());
            // Nor create one in their name, or make an organization dashboard someone else's personal one.
            $this->expectQueryError('42501', fn () => $this->insertDashboard($this->orgA, ['visibility' => 'personal', 'created_by' => $owner->id]));
            $this->expectQueryError('42501', fn () => DB::table('dashboards')->where('id', $shared)->update(['visibility' => 'personal', 'created_by' => $owner->id]));
            $this->expectQueryError('42501', fn () => $this->insertWidget($this->orgA, $personal));
        });
        $this->assertSame('Milik saya', DB::table('dashboards')->where('id', $personal)->value('name'));
    }

    public function test_shares_target_exactly_one_member_or_role_of_the_same_organization(): void
    {
        $dashboard = $this->insertDashboard($this->orgA);
        $roleA = $this->createRole($this->orgA, 'VIEWER_DASH', ['dashboard.view']);
        $roleB = $this->createRole($this->orgB, 'VIEWER_DASH', ['dashboard.view']);

        $this->expectQueryError('23514', fn () => $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminA->id, 'role_id' => $roleA->id]));
        $this->expectQueryError('23514', fn () => $this->insertShare($this->orgA, $dashboard, []));
        $this->expectQueryError('23514', fn () => $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminA->id, 'permission' => 'owner']));
        $this->expectQueryError('23503', fn () => $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminB->id]));
        $this->expectQueryError('23503', fn () => $this->insertShare($this->orgA, $dashboard, ['role_id' => $roleB->id]));

        $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminA->id]);
        $this->insertShare($this->orgA, $dashboard, ['role_id' => $roleA->id, 'permission' => 'edit']);
        $this->expectQueryError('23505', fn () => $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminA->id, 'permission' => 'manage']));

        // Personal dashboards are never shared.
        $personal = $this->insertDashboard($this->orgA, ['visibility' => 'personal', 'created_by' => $this->adminA->id]);
        $this->expectQueryError('P0001', fn () => $this->insertShare($this->orgA, $personal, ['role_id' => $roleA->id]));

        // Deleting the role takes its shares with it.
        DB::table('roles')->where('id', $roleA->id)->delete();
        $this->assertSame(['user'], DB::table('dashboard_shares')->where('dashboard_id', $dashboard)->get()
            ->map(fn ($s) => $s->user_id ? 'user' : 'role')->all());
    }

    public function test_preferences_are_private_and_the_default_dashboard_stays_in_the_organization(): void
    {
        $member = $this->createTenantUser($this->orgA, ['ADMIN']);
        $category = $this->insertDashboard($this->orgA, ['dashboard_type' => 'category', 'category_id' => $this->categoryA->id]);
        $this->insertPreference($this->orgA, $member, $category);
        $this->expectQueryError('23503', fn () => $this->insertPreference($this->orgA, $this->adminA, $this->insertDashboard($this->orgB)));
        $this->expectQueryError('23503', fn () => $this->insertPreference($this->orgA, $this->adminB, null));
        $this->expectQueryError('23505', fn () => $this->insertPreference($this->orgA, $member, null));

        $this->asTenant($this->orgA, $member, fn () => $this->assertSame(1, DB::table('user_dashboard_preferences')->count()));
        $other = $this->createTenantUser($this->orgA, ['ADMIN']);
        $this->asTenant($this->orgA, $this->adminA, function () use ($other) {
            $this->assertSame(0, DB::table('user_dashboard_preferences')->count());
            $this->expectQueryError('42501', fn () => $this->insertPreference($this->orgA, $other, null));
        });

        // Removing the category removes its dashboard and clears the choice (the Overview applies again).
        DB::table('tenant_asset_categories')->where('id', $this->categoryA->id)->delete();
        $this->assertSame(0, DB::table('dashboards')->where('id', $category)->count());
        $this->assertNull(DB::table('user_dashboard_preferences')->where('user_id', $member->id)->value('default_dashboard_id'));
        $this->assertSame($this->orgA->id, DB::table('user_dashboard_preferences')->where('user_id', $member->id)->value('organization_id'));
    }

    public function test_another_organization_sees_and_writes_none_of_the_dashboard_data(): void
    {
        $dashboard = $this->insertDashboard($this->orgA);
        $this->insertWidget($this->orgA, $dashboard);
        $this->insertShare($this->orgA, $dashboard, ['user_id' => $this->adminA->id]);
        $this->insertPreference($this->orgA, $this->adminA, $dashboard);
        $this->insertTemplate($this->orgA, 'alpha_only');

        $this->asTenant($this->orgB, $this->adminB, function () use ($dashboard) {
            foreach (['dashboards', 'dashboard_widgets', 'dashboard_shares', 'user_dashboard_preferences'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertSame(0, DB::table('dashboard_templates')->whereNotNull('organization_id')->count());
            $this->assertSame(0, Dashboard::query()->count());
            $this->assertSame(0, DB::table('dashboards')->where('id', $dashboard)->update(['name' => 'Taken']));
            $this->expectQueryError('42501', fn () => $this->insertDashboard($this->orgA));
            $this->expectQueryError('42501', fn () => DB::table('dashboard_templates')->insert($this->templateRow($this->orgA, 'planted')));

            // The application layer refuses as well, before the database is reached.
            $this->expectException(LogicException::class);
            (new Dashboard(['name' => 'X']))->forceFill(['organization_id' => $this->orgA->id, 'dashboard_type' => 'custom', 'visibility' => 'organization'])->save();
        });
    }

    private function category(Organization $organization, string $code): TenantAssetCategory
    {
        $category = (new TenantAssetCategory)->forceFill(['organization_id' => $organization->id, 'code' => $code, 'name' => "Kategori {$code}",
            'generic_category_id' => GenericAssetCategory::query()->where('code', 'UMUM')->value('id')]);
        $category->save();

        return $category;
    }

    /** @param  array<string, mixed>  $attributes */
    private function insertDashboard(Organization $organization, array $attributes = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('dashboards')->insert($attributes + ['id' => $id, 'organization_id' => $organization->id, 'name' => 'Dashboard',
            'dashboard_type' => 'custom', 'visibility' => 'organization', 'created_at' => now(), 'updated_at' => now()]);

        return $attributes['id'] ?? $id;
    }

    private function insertWidget(Organization $organization, string $dashboardId): string
    {
        $id = (string) Str::uuid7();
        DB::table('dashboard_widgets')->insert(['id' => $id, 'organization_id' => $organization->id, 'dashboard_id' => $dashboardId,
            'widget_catalog_id' => DB::table('widget_catalog')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @param  array<string, mixed>  $target */
    private function insertShare(Organization $organization, string $dashboardId, array $target): void
    {
        DB::table('dashboard_shares')->insert($target + ['id' => (string) Str::uuid7(), 'organization_id' => $organization->id,
            'dashboard_id' => $dashboardId, 'permission' => 'view', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertPreference(Organization $organization, User $user, ?string $dashboardId): void
    {
        DB::table('user_dashboard_preferences')->insert(['id' => (string) Str::uuid7(),
            'organization_id' => $organization->id, 'user_id' => $user->id, 'default_dashboard_id' => $dashboardId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function insertTemplate(Organization $organization, string $key, array $attributes = []): string
    {
        $row = $attributes + $this->templateRow($organization, $key);
        DB::table('dashboard_templates')->insert($row);

        return $row['id'];
    }

    /** @return array<string, mixed> */
    private function templateRow(?Organization $organization, string $key): array
    {
        return ['id' => (string) Str::uuid7(), 'organization_id' => $organization?->id, 'template_key' => $key, 'name' => $key,
            'dashboard_type' => 'custom', 'scope' => $organization ? 'organization' : 'system', 'created_at' => now(), 'updated_at' => now()];
    }

    private function asTenant(Organization $organization, User $user, callable $callback): void
    {
        $this->tenancy()->runAsTenant(new TenantContext($organization, $user), $callback(...));
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
