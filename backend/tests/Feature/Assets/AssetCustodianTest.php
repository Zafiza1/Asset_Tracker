<?php

namespace Tests\Feature\Assets;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Tests\TestCase;

/** F-09: an asset's custodian (member) and department. */
class AssetCustodianTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_register_and_show_custodian_and_department(): void
    {
        $sari = $this->createTenantUser($this->org, [], [], ['name' => 'Sari Dewi']);

        $asset = $this->register(['custodian_user_id' => $sari->id, 'department_id' => $this->masters['department']->id])
            ->assertCreated()
            ->assertJsonPath('data.custodian.name', 'Sari Dewi')
            ->assertJsonPath('data.custodian.is_active', true)
            ->assertJsonPath('data.department.code', 'DEP1')
            ->json('data');

        $this->getJson("/api/assets/{$asset['id']}")->assertOk()
            ->assertJsonPath('data.custodian_user_id', $sari->id)
            ->assertJsonPath('data.department_id', $this->masters['department']->id);

        // Both optional: an asset without them shows null.
        $this->register([])->assertCreated()->assertJsonPath('data.custodian', null)->assertJsonPath('data.department', null);
    }

    public function test_custodian_must_be_an_active_member_of_this_organization(): void
    {
        $outsider = $this->createTenantUser($this->createOrganization('BETA')['organization']);
        $this->assertErrorCode($this->register(['custodian_user_id' => $outsider->id]), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->register(['custodian_user_id' => (string) Str::uuid()]), 422, 'INVALID_REFERENCE');

        $left = $this->createTenantUser($this->org);
        $this->deactivateMember($left);
        $this->assertErrorCode($this->register(['custodian_user_id' => $left->id]), 422, 'INVALID_REFERENCE');

        // Another organization's department is not usable either.
        $mastersB = $this->createMasterFixtures($this->createOrganization('GAMMA')['organization'], 'B');
        $this->assertErrorCode($this->register(['department_id' => $mastersB['department']->id]), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->register(['custodian_user_id' => 'bukan-uuid']), 422, 'VALIDATION_FAILED');
    }

    public function test_custodian_who_left_stays_marked_inactive_and_other_edits_still_work(): void
    {
        $budi = $this->createTenantUser($this->org, [], [], ['name' => 'Budi']);
        $asset = $this->register(['custodian_user_id' => $budi->id])->json('data');
        $this->deactivateMember($budi);

        $this->getJson("/api/assets/{$asset['id']}")->assertOk()
            ->assertJsonPath('data.custodian.name', 'Budi')
            ->assertJsonPath('data.custodian.is_active', false);

        // Resending the same (now inactive) custodian with another change is not a new choice.
        $this->patchJson("/api/assets/{$asset['id']}", ['name' => 'Laptop Baru', 'custodian_user_id' => $budi->id])->assertOk()
            ->assertJsonPath('data.custodian.name', 'Budi');
        // Clearing is always allowed.
        $this->patchJson("/api/assets/{$asset['id']}", ['custodian_user_id' => null])->assertOk()->assertJsonPath('data.custodian', null);
        // Picking them again is not.
        $this->assertErrorCode($this->patchJson("/api/assets/{$asset['id']}", ['custodian_user_id' => $budi->id]), 422, 'INVALID_REFERENCE');
    }

    public function test_list_filters_by_custodian_department_and_mine(): void
    {
        $sari = $this->createTenantUser($this->org, ['ADMIN']);
        $mine = $this->register(['name' => 'Punya admin', 'custodian_user_id' => $this->admin->id])->json('data.id');
        $hers = $this->register(['name' => 'Punya Sari', 'custodian_user_id' => $sari->id, 'department_id' => $this->masters['department']->id])->json('data.id');
        $this->register(['name' => 'Tanpa pemegang']);

        $this->assertSame([$hers], array_column($this->getJson("/api/assets?custodian_id={$sari->id}")->assertOk()->json('data'), 'id'));
        $this->assertSame([$hers], array_column($this->getJson("/api/assets?department_id={$this->masters['department']->id}")->json('data'), 'id'));
        $this->assertSame([$mine], array_column($this->getJson('/api/assets?mine=1')->json('data'), 'id'));
        $this->assertErrorCode($this->getJson('/api/assets?custodian_id=x'), 422, 'VALIDATION_FAILED');
    }

    public function test_changes_appear_in_history_with_names_and_in_audit(): void
    {
        $budi = $this->createTenantUser($this->org, [], [], ['name' => 'Budi']);
        $sari = $this->createTenantUser($this->org, [], [], ['name' => 'Sari']);
        $asset = $this->register(['custodian_user_id' => $budi->id])->json('data');
        $this->travel(1)->minutes();
        $this->patchJson("/api/assets/{$asset['id']}", ['custodian_user_id' => $sari->id, 'department_id' => $this->masters['department']->id])->assertOk();

        $events = $this->getJson("/api/assets/{$asset['id']}/timeline")->assertOk()->json('data');
        $changes = collect($events[0]['changes'])->keyBy('field');
        $this->assertSame(['Penanggung jawab', 'Budi', 'Sari'], [$changes['custodian_user_id']['label'], $changes['custodian_user_id']['before'], $changes['custodian_user_id']['after']]);
        $this->assertSame(['Departemen', null, 'Departemen 1'], [$changes['department_id']['label'], $changes['department_id']['before'], $changes['department_id']['after']]);
    }

    public function test_department_used_by_an_active_asset_cannot_be_archived(): void
    {
        $asset = $this->register(['department_id' => $this->masters['department']->id])->json('data');
        $this->assertErrorCode($this->postJson("/api/departments/{$this->masters['department']->id}/archive"), 409, 'DEPARTMENT_IN_USE');

        $this->postJson("/api/assets/{$asset['id']}/archive")->assertOk();
        $this->postJson("/api/departments/{$this->masters['department']->id}/archive")->assertOk();
    }

    public function test_custodian_options_list_active_members_for_editors_only(): void
    {
        $this->createTenantUser($this->org, [], [], ['name' => 'Sari Dewi', 'email' => 'sari@alpha.test']);
        $left = $this->createTenantUser($this->org, [], [], ['name' => 'Sari Lama']);
        $this->deactivateMember($left);
        $this->createTenantUser($this->createOrganization('BETA')['organization'], [], [], ['name' => 'Sari Beta']);

        $names = array_column($this->getJson('/api/assets/custodian-options?search=sari')->assertOk()->json('data'), 'name');
        $this->assertSame(['Sari Dewi'], $names);

        // A read-only user cannot list the organization's members through this picker.
        $this->flushSession();
        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');
        $this->assertErrorCode($this->getJson('/api/assets/custodian-options'), 403, 'FORBIDDEN');
    }

    public function test_home_shows_my_assets_and_assets_whose_custodian_left(): void
    {
        $budi = $this->createTenantUser($this->org, [], [], ['name' => 'Budi']);
        $this->register(['name' => 'Laptop Admin', 'custodian_user_id' => $this->admin->id]);
        $this->register(['name' => 'Printer Budi', 'custodian_user_id' => $budi->id]);
        $this->deactivateMember($budi);

        $cards = collect($this->getJson('/api/home/cards')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(1, $cards['my_assets']['count']);
        $this->assertSame(['Laptop Admin'], array_column($cards['my_assets']['items'], 'name'));
        $this->assertSame(1, $cards['inactive_custodians']['count']);
        $this->assertSame(['Printer Budi', 'Budi'], [$cards['inactive_custodians']['items'][0]['asset']['name'], $cards['inactive_custodians']['items'][0]['custodian']]);
    }

    public function test_assets_by_department_widget_counts_each_department_and_the_unassigned(): void
    {
        $this->register(['department_id' => $this->masters['department']->id]);
        $this->register(['department_id' => $this->masters['department']->id]);
        $this->register([]);

        $data = $this->widgetData($this->org, $this->admin, 'assets_by_department');
        $this->assertSame(['Departemen 1' => 2, 'Tanpa departemen' => 1], array_combine($data['categories'], $data['series'][0]['data']));
    }

    private function deactivateMember(User $user): void
    {
        OrganizationMember::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->where('user_id', $user->id)
            ->update(['status' => User::STATUS_DEACTIVATED]);
    }

    /** @param array<string, mixed> $data */
    private function register(array $data)
    {
        $this->relaxDefaultCondition();

        return $this->postJson('/api/assets', [
            'name' => 'Laptop',
            'status' => 'available',
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
            ...$data,
        ]);
    }
}
