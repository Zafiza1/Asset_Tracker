<?php

namespace Tests\Feature\Security;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * SEC-03 / SEC-04: delegation of roles and data scopes, and location scope boundaries.
 *
 * Fixture (organization ALPHA):
 *   Gedung A (root) ── A-1 (child)        Gedung B (root)
 *   asset "Aset A" at A-1, asset "Aset B" at Gedung B.
 *   "head": custom role KEPALA (SUPERVISOR's permissions plus user, master, asset and transaction
 *   management, no role.manage),
 *   data scope = location Gedung A.
 */
class DataScopeEscalationTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    private Location $gedungA;

    private Location $a1;

    private Location $gedungB;

    private Asset $assetA;

    private Asset $assetB;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->gedungA = $this->location('GDA', 'Gedung A');
        $this->a1 = $this->location('GDA-1', 'Lantai A-1', $this->gedungA);
        $this->gedungB = $this->location('GDB', 'Gedung B');
        $this->assetA = $this->asset('A', $this->a1);
        $this->assetB = $this->asset('B', $this->gedungB);

        $this->customRole('KEPALA', [
            'organization.view', 'catalog.generic.view', 'user.view', 'user.update',
            'master.tenant.view', 'master.tenant.create', 'master.tenant.update', 'master.tenant.archive',
            'asset.view', 'asset.create', 'asset.update', 'asset.transfer', 'asset.archive', 'asset.restore',
            'transaction.view', 'transaction.create', 'report.view',
        ]);
        $this->head = $this->createTenantUser($this->org, ['KEPALA'], $this->scopeAt($this->gedungA));
    }

    // ------------------------------------------------------------------ SEC-03: scope delegation

    public function test_user_cannot_change_their_own_data_scope(): void
    {
        $this->loginAs($this->head);

        $this->assertErrorCode($this->putJson("/api/users/{$this->head->id}/scopes", ['scopes' => [['scope_type' => 'organization']]]), 422, 'CANNOT_CHANGE_OWN_SCOPE');
        $this->assertErrorCode($this->putJson("/api/users/{$this->head->id}/scopes", ['scopes' => $this->scopeAt($this->a1)]), 422, 'CANNOT_CHANGE_OWN_SCOPE');

        $this->assertSame(["location:{$this->gedungA->id}"], $this->scopesOf($this->head));
        $this->getJson("/api/assets/{$this->assetB->id}")->assertNotFound();
    }

    public function test_scope_outside_the_actors_own_scope_cannot_be_granted(): void
    {
        $viewer = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        $this->loginAs($this->head);

        foreach ([[['scope_type' => 'organization']], $this->scopeAt($this->gedungB), [...$this->scopeAt($this->a1), ...$this->scopeAt($this->gedungB)]] as $scopes) {
            $this->assertErrorCode($this->putJson("/api/users/{$viewer->id}/scopes", ['scopes' => $scopes]), 403, 'SCOPE_ESCALATION');
        }
        $this->assertSame(["location:{$this->a1->id}"], $this->scopesOf($viewer));

        // Within the actor's scope (a location and its sub-locations) is a legitimate delegation.
        $this->putJson("/api/users/{$viewer->id}/scopes", ['scopes' => $this->scopeAt($this->gedungA)])->assertOk();
        $this->assertSame(["location:{$this->gedungA->id}"], $this->scopesOf($viewer));
    }

    public function test_scope_of_a_user_broader_than_the_actor_cannot_be_changed(): void
    {
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $elsewhere = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->gedungB));
        $this->loginAs($this->head);

        $this->assertErrorCode($this->putJson("/api/users/{$wide->id}/scopes", ['scopes' => $this->scopeAt($this->a1)]), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->putJson("/api/users/{$elsewhere->id}/scopes", ['scopes' => []]), 403, 'SCOPE_ESCALATION');
        $this->assertSame(['organization'], $this->scopesOf($wide));
        $this->assertSame(["location:{$this->gedungB->id}"], $this->scopesOf($elsewhere));
    }

    public function test_scope_of_a_user_with_more_permissions_cannot_be_changed(): void
    {
        $this->customRole('MANAGER', ['user.view', 'asset.view', 'asset.dispose', 'transaction.view', 'transaction.approve']);
        $manager = $this->createTenantUser($this->org, ['MANAGER'], $this->scopeAt($this->a1));
        $this->loginAs($this->head);

        $this->assertErrorCode($this->putJson("/api/users/{$manager->id}/scopes", ['scopes' => $this->scopeAt($this->gedungA)]), 403, 'PRIVILEGE_ESCALATION');
        $this->assertSame(["location:{$this->a1->id}"], $this->scopesOf($manager));
    }

    public function test_scope_references_must_exist_in_this_organization(): void
    {
        $viewer = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        ['organization' => $beta] = $this->createOrganization('BETA');
        $betaLocation = $this->createMasterFixtures($beta, 'B')['location'];
        $this->loginAs($this->admin);

        foreach ([$betaLocation->id, (string) Str::uuid()] as $ref) {
            $this->assertErrorCode($this->putJson("/api/users/{$viewer->id}/scopes", ['scopes' => [['scope_type' => 'location', 'ref_id' => $ref]]]), 422, 'INVALID_SCOPE');
        }
        $this->assertSame(["location:{$this->a1->id}"], $this->scopesOf($viewer));
    }

    public function test_scoped_user_cannot_take_over_a_broader_account_by_resetting_its_password(): void
    {
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $inside = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        $this->loginAs($this->head);
        $password = 'Sementara-Baru-2026';

        $this->assertErrorCode($this->postJson("/api/users/{$wide->id}/reset-password", ['password' => $password]), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->patchJson("/api/users/{$wide->id}", ['email' => 'penyerang@luar.test']), 403, 'SCOPE_ESCALATION');
        $this->assertFalse(Hash::check($password, $wide->refresh()->password));
        $this->assertNotSame('penyerang@luar.test', $wide->email);

        $this->postJson("/api/users/{$inside->id}/reset-password", ['password' => $password])->assertNoContent();
        $this->assertTrue(Hash::check($password, $inside->refresh()->password));
    }

    public function test_organization_admin_still_manages_any_scope_and_changes_are_audited(): void
    {
        $viewer = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        $this->loginAs($this->admin);

        $this->putJson("/api/users/{$viewer->id}/scopes", ['scopes' => [['scope_type' => 'organization']]])->assertOk();
        $this->putJson("/api/users/{$this->head->id}/scopes", ['scopes' => $this->scopeAt($this->gedungB)])->assertOk();

        $log = AuditLog::query()->where('action', 'user.scopes_changed')->where('entity_id', $viewer->id)->sole();
        $this->assertSame(["location:{$this->a1->id}"], $log->before['scopes']);
        $this->assertSame(['organization'], $log->after['scopes']);
        $this->assertSame($this->admin->id, $log->actor_user_id);
    }

    public function test_revoked_scope_removes_access_on_the_next_request(): void
    {
        $this->loginAs($this->head);
        $this->getJson("/api/assets/{$this->assetA->id}")->assertOk();

        $this->loginAs($this->admin);
        $this->putJson("/api/users/{$this->head->id}/scopes", ['scopes' => []])->assertOk();

        // An empty scope is fail-closed, never "everything".
        $this->loginAs($this->head);
        $this->getJson('/api/assets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/assets/{$this->assetA->id}")->assertNotFound();
        $this->getJson('/api/transactions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.summary.total', 0);
        $this->postJson('/api/transactions', ['asset_id' => $this->assetA->id, 'type' => 'note', 'notes' => 'x'])->assertNotFound();
        $this->assertErrorCode($this->postJson('/api/locations', $this->locationPayload('BARU', $this->a1)), 422, 'LOCATION_OUT_OF_SCOPE');
    }

    public function test_scope_without_any_location_never_allows_a_location(): void
    {
        // Department scopes grant no assets; they must not count as "every location" either.
        Notification::fake();
        $department = $this->masters['department'];
        $this->customRole('KOORDINATOR', ['user.view', 'user.create', 'role.manage', 'role.view',
            'organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'asset.create', 'transaction.view', 'report.view']);
        $coordinator = $this->createTenantUser($this->org, ['KOORDINATOR'], [['scope_type' => 'department', 'ref_id' => $department->id]]);
        $this->loginAs($coordinator);

        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'X', 'category_id' => $this->masters['category']->id, 'location_id' => $this->gedungB->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->postJson('/api/invitations', $this->invitation('calon@alpha.test', $this->scopeAt($this->gedungB))), 403, 'SCOPE_ESCALATION');
        $this->postJson('/api/invitations', $this->invitation('calon@alpha.test', [['scope_type' => 'department', 'ref_id' => $department->id]]))->assertCreated();
        $this->assertFalse(Asset::query()->where('name', 'X')->exists());
    }

    // ------------------------------------------------------------------ role delegation

    public function test_roles_beyond_the_actors_permissions_cannot_be_assigned_to_anyone_including_themselves(): void
    {
        $this->customRole('PENGATUR_ROLE', ['role.view', 'role.manage', 'user.view', 'asset.view']);
        $actor = $this->createTenantUser($this->org, ['PENGATUR_ROLE']);
        $viewer = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $ownRole = Role::query()->where('code', 'PENGATUR_ROLE')->sole();
        $this->loginAs($actor);

        foreach ([$actor, $viewer] as $target) {
            $this->assertErrorCode($this->putJson("/api/users/{$target->id}/roles", ['role_ids' => [$this->roleId($this->org, RoleTemplates::ADMIN)]]), 403, 'PRIVILEGE_ESCALATION');
        }
        $this->assertErrorCode($this->patchJson("/api/roles/{$ownRole->id}", ['permissions' => ['role.view', 'role.manage', 'user.view', 'asset.view', 'user.update']]), 403, 'PRIVILEGE_ESCALATION');
        $this->assertErrorCode($this->postJson('/api/roles', ['code' => 'SUPER', 'name' => 'Super', 'permissions' => ['audit.view']]), 403, 'PRIVILEGE_ESCALATION');

        $this->assertSame(['PENGATUR_ROLE'], $actor->roles()->pluck('code')->all());
        $this->assertSame(['SUPERVISOR'], $viewer->roles()->pluck('code')->all());
        $this->assertNotContains('user.update', $ownRole->permissions()->pluck('code')->all());
    }

    public function test_platform_roles_and_roles_of_another_organization_cannot_be_assigned_through_the_tenant(): void
    {
        Notification::fake();
        ['organization' => $beta] = $this->createOrganization('BETA');
        $viewer = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $platformRole = PlatformRole::query()->where('code', RoleTemplates::SUPER_ADMIN)->value('id');
        $foreignRole = $this->roleId($beta, RoleTemplates::ADMIN);
        $this->loginAs($this->admin);

        foreach ([$platformRole, $foreignRole] as $roleId) {
            $this->assertErrorCode($this->putJson("/api/users/{$viewer->id}/roles", ['role_ids' => [$roleId]]), 422, 'INVALID_ROLE');
            $this->assertErrorCode($this->postJson('/api/invitations', ['email' => 'calon@alpha.test', 'role_ids' => [$roleId], 'scopes' => [['scope_type' => 'organization']]]), 422, 'INVALID_ROLE');
        }
        $this->assertErrorCode($this->postJson('/api/roles', ['code' => 'PLAT', 'name' => 'Plat', 'permissions' => [PermissionCatalog::codes(PermissionCatalog::SCOPE_PLATFORM)[0]]]), 422, 'UNKNOWN_PERMISSION');

        $this->assertSame(['SUPERVISOR'], $viewer->roles()->pluck('code')->all());
        $this->assertFalse(DB::table('platform_user_roles')->where('user_id', $viewer->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_scoped_role_manager_cannot_give_permissions_to_a_broader_user_through_roles(): void
    {
        // Granting a role to an organization-wide user would let them use it outside the actor's scope.
        [$manager, $editor] = $this->scopedRoleManager();
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $inside = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        $this->loginAs($manager);

        $this->assertErrorCode($this->putJson("/api/users/{$wide->id}/roles", ['role_ids' => [$this->roleId($this->org, 'SUPERVISOR'), $editor->id]]), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->putJson("/api/users/{$wide->id}/roles", ['role_ids' => []]), 403, 'SCOPE_ESCALATION');
        $this->assertSame(['SUPERVISOR'], $wide->roles()->pluck('code')->all());

        $this->putJson("/api/users/{$inside->id}/roles", ['role_ids' => [$this->roleId($this->org, 'SUPERVISOR'), $editor->id]])->assertOk();
        $this->assertSame(['EDITOR', 'SUPERVISOR'], $inside->roles()->orderBy('code')->pluck('code')->all());

        $this->loginAs($wide);
        $this->patchJson("/api/assets/{$this->assetB->id}", ['name' => 'Diubah'])->assertForbidden();
    }

    public function test_scoped_role_manager_cannot_change_permissions_of_a_role_held_outside_their_scope(): void
    {
        [$manager, $editor] = $this->scopedRoleManager();
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR', 'EDITOR']);
        $permissions = ['asset.view', 'asset.update'];
        $this->loginAs($manager);

        $this->assertErrorCode($this->patchJson("/api/roles/{$editor->id}", ['permissions' => [...$permissions, 'transaction.view']]), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->patchJson("/api/roles/{$editor->id}", ['permissions' => ['asset.view']]), 403, 'SCOPE_ESCALATION');
        $this->assertEqualsCanonicalizing($permissions, $editor->permissions()->pluck('code')->all());

        // Renaming (same permission set, as the role form sends it) changes nobody's access.
        $this->patchJson("/api/roles/{$editor->id}", ['name' => 'Editor Aset', 'permissions' => array_reverse($permissions)])->assertOk();

        // Once every holder is within the actor's scope, the role can be changed.
        $this->loginAs($this->admin);
        $this->putJson("/api/users/{$wide->id}/scopes", ['scopes' => $this->scopeAt($this->a1)])->assertOk();
        $this->loginAs($manager);
        $this->patchJson("/api/roles/{$editor->id}", ['permissions' => [...$permissions, 'transaction.view']])->assertOk();
    }

    public function test_role_holder_scope_check_takes_the_same_queries_for_any_number_of_holders(): void
    {
        [$manager, $editor] = $this->scopedRoleManager();
        $this->createTenantUser($this->org, [$editor->code], $this->scopeAt($this->a1));
        $this->loginAs($manager);
        $queries = function (array $permissions): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->patchJson("/api/roles/{$this->roleId($this->org, 'EDITOR')}", ['permissions' => $permissions])->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $one = $queries(['asset.view']);
        foreach (range(1, 5) as $i) {
            $this->createTenantUser($this->org, ['EDITOR'], $this->scopeAt($i % 2 ? $this->gedungA : $this->a1));
        }
        $this->loginAs($manager);
        $this->assertSame($one, $queries(['asset.view', 'asset.update']));
    }

    public function test_scoped_user_cannot_suspend_or_deactivate_a_broader_member(): void
    {
        [$manager] = $this->scopedRoleManager();
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $inside = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1));
        $this->loginAs($manager);

        $this->assertErrorCode($this->postJson("/api/users/{$wide->id}/deactivate"), 403, 'SCOPE_ESCALATION');
        $this->assertSame(User::STATUS_ACTIVE, $wide->membership()->value('status'));

        $this->postJson("/api/users/{$inside->id}/deactivate")->assertOk();
        $this->assertSame(User::STATUS_DEACTIVATED, $inside->membership()->value('status'));
    }

    public function test_scoped_user_cannot_change_the_job_data_of_a_broader_member(): void
    {
        $wide = $this->createTenantUser($this->org, ['SUPERVISOR'], attributes: ['name' => 'Luas']);
        $inside = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->a1), ['name' => 'Dalam']);
        $department = $this->masters['department'];
        $this->loginAs($this->head);

        $this->assertErrorCode($this->patchJson("/api/users/{$wide->id}", ['name' => 'Luas', 'job_title' => 'Diubah']), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->patchJson("/api/users/{$wide->id}", ['name' => 'Luas', 'home_department_id' => $department->id]), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->patchJson("/api/users/{$wide->id}", ['name' => 'Luas', 'employee_number' => 'X-1']), 403, 'SCOPE_ESCALATION');
        $this->assertSame([null, null, null], array_values($wide->membership()->first()->only(['job_title', 'home_department_id', 'employee_number'])));
        // Re-sending the current values changes nothing, so it needs no authority over the member.
        $this->patchJson("/api/users/{$wide->id}", ['name' => 'Luas', 'job_title' => null])->assertOk();

        $this->patchJson("/api/users/{$inside->id}", ['name' => 'Dalam', 'job_title' => 'Staf', 'home_department_id' => $department->id])->assertOk()
            ->assertJsonPath('data.job_title', 'Staf');
        $this->patchJson("/api/users/{$this->head->id}", ['name' => $this->head->name, 'job_title' => 'Kepala Gedung'])->assertOk();
    }

    public function test_scoped_inviter_cannot_revoke_an_invitation_beyond_their_authority(): void
    {
        Notification::fake();
        $this->customRole('PENGUNDANG', ['user.view', 'user.create', 'role.manage', 'role.view',
            'organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'transaction.view', 'report.view']);
        $inviter = $this->createTenantUser($this->org, ['PENGUNDANG'], $this->scopeAt($this->gedungA));

        $this->loginAs($this->admin);
        $wideId = $this->postJson('/api/invitations', $this->invitation('luas@alpha.test', [['scope_type' => 'organization']]))->assertCreated()->json('data.id');
        $adminId = $this->postJson('/api/invitations', [...$this->invitation('admin@alpha.test', $this->scopeAt($this->a1)),
            'role_ids' => [$this->roleId($this->org, RoleTemplates::ADMIN)]])->assertCreated()->json('data.id');
        $insideId = $this->postJson('/api/invitations', $this->invitation('dalam@alpha.test', $this->scopeAt($this->a1)))->assertCreated()->json('data.id');

        $this->loginAs($inviter);
        $this->assertErrorCode($this->postJson("/api/invitations/{$wideId}/revoke"), 403, 'SCOPE_ESCALATION');
        $this->assertErrorCode($this->postJson("/api/invitations/{$adminId}/revoke"), 403, 'PRIVILEGE_ESCALATION');
        $this->assertSame(['pending', 'pending'], DB::table('organization_invitations')->whereIn('id', [$wideId, $adminId])->pluck('status')->all());

        $this->postJson("/api/invitations/{$insideId}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->loginAs($this->admin);
        $this->postJson("/api/invitations/{$wideId}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
    }

    // ------------------------------------------------------------------ SEC-04: location hierarchy

    public function test_location_outside_the_scope_cannot_be_moved_into_it(): void
    {
        $this->loginAs($this->head);
        $pathB = $this->gedungB->path;

        $this->assertErrorCode($this->patchJson("/api/locations/{$this->gedungB->id}", ['parent_id' => $this->gedungA->id]), 403, 'LOCATION_OUT_OF_SCOPE');

        $this->assertSame($pathB, $this->gedungB->refresh()->path);
        $this->assertNull($this->gedungB->parent_id);
        $this->getJson("/api/assets/{$this->assetB->id}")->assertNotFound();
        $this->assertErrorCode($this->transaction($this->assetB, 'note', ['notes' => 'x']), 404, 'NOT_FOUND');
    }

    public function test_location_inside_the_scope_cannot_be_moved_out_of_it(): void
    {
        // Moving A-1 under B would hand its assets to everyone scoped to B.
        $otherHead = $this->createTenantUser($this->org, ['SUPERVISOR'], $this->scopeAt($this->gedungB));
        $this->loginAs($this->head);

        $this->assertErrorCode($this->patchJson("/api/locations/{$this->a1->id}", ['parent_id' => $this->gedungB->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->patchJson("/api/locations/{$this->a1->id}", ['parent_id' => null]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->patchJson("/api/locations/{$this->gedungA->id}", ['parent_id' => $this->gedungB->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertSame($this->gedungA->id, $this->a1->refresh()->parent_id);
        $this->assertNull($this->gedungA->refresh()->parent_id);

        $this->loginAs($otherHead);
        $this->getJson("/api/assets/{$this->assetA->id}")->assertNotFound();
    }

    public function test_locations_cannot_be_created_outside_the_scope(): void
    {
        $this->loginAs($this->head);

        $this->assertErrorCode($this->postJson('/api/locations', $this->locationPayload('B-BARU', $this->gedungB)), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->postJson('/api/locations', $this->locationPayload('ROOT-BARU')), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertFalse(Location::query()->whereIn('code', ['B-BARU', 'ROOT-BARU'])->exists());

        $this->postJson('/api/locations', $this->locationPayload('A-BARU', $this->a1))->assertCreated();
    }

    public function test_locations_outside_the_scope_cannot_be_edited_archived_or_restored(): void
    {
        $empty = $this->location('GDC', 'Gedung C');
        $this->loginAs($this->head);

        $this->assertErrorCode($this->patchJson("/api/locations/{$this->gedungB->id}", ['name' => 'Diubah']), 403, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->postJson("/api/locations/{$empty->id}/archive"), 403, 'LOCATION_OUT_OF_SCOPE');
        $empty->forceFill(['status' => 'archived'])->save();
        $this->assertErrorCode($this->postJson("/api/locations/{$empty->id}/restore"), 403, 'LOCATION_OUT_OF_SCOPE');
        $this->assertSame('Gedung B', $this->gedungB->refresh()->name);

        // Inside the scope the structure stays manageable.
        $this->patchJson("/api/locations/{$this->a1->id}", ['name' => 'Lantai 1'])->assertOk();
        $sub = $this->location('GDA-2', 'Lantai A-2', $this->gedungA);
        $this->patchJson("/api/locations/{$sub->id}", ['parent_id' => $this->a1->id])->assertOk()->assertJsonPath('data.parent.code', 'GDA-1');
        $this->postJson("/api/locations/{$sub->id}/archive")->assertOk();
        $this->postJson("/api/locations/{$sub->id}/restore")->assertOk();
    }

    public function test_organization_wide_admin_can_still_restructure_any_location(): void
    {
        $this->loginAs($this->admin);

        $this->patchJson("/api/locations/{$this->gedungB->id}", ['parent_id' => $this->gedungA->id])->assertOk()->assertJsonPath('data.parent.code', 'GDA');
        $this->patchJson("/api/locations/{$this->gedungB->id}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent', null);
        $this->postJson('/api/locations', $this->locationPayload('ROOT-BARU'))->assertCreated();
    }

    // ------------------------------------------------------------------ asset reads and writes

    public function test_detail_search_list_history_and_dashboard_only_show_assets_in_scope(): void
    {
        $this->loginAs($this->head);

        $this->getJson('/api/assets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->assetA->id);
        $this->getJson('/api/assets?search=Aset%20B')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/assets?location_id={$this->gedungB->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/assets?archived=all')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/assets/{$this->assetB->id}")->assertNotFound();
        $this->getJson("/api/assets/{$this->assetB->id}/transactions")->assertNotFound();

        $trxB = AssetTransaction::query()->where('asset_id', $this->assetB->id)->value('id');
        $this->getJson('/api/transactions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/transactions?asset_id={$this->assetB->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/transactions/{$trxB}")->assertNotFound();

        $dashboard = $this->getJson('/api/dashboard')->assertOk();
        $dashboard->assertJsonPath('data.summary.total', 1);
        $this->assertSame([$this->a1->id], array_column($dashboard->json('data.by_location'), 'id'));
    }

    public function test_out_of_scope_assets_cannot_be_modified(): void
    {
        $this->loginAs($this->head);
        $id = $this->assetB->id;

        $this->patchJson("/api/assets/{$id}", ['name' => 'Diubah'])->assertNotFound();
        $this->postJson("/api/assets/{$id}/archive")->assertNotFound();
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'X', 'category_id' => $this->masters['category']->id, 'location_id' => $this->gedungB->id]), 422, 'LOCATION_OUT_OF_SCOPE');
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'X', 'category_id' => $this->masters['category']->id]), 422, 'LOCATION_OUT_OF_SCOPE');

        // location_id is not an editable field: the asset stays where it is.
        $this->patchJson("/api/assets/{$this->assetA->id}", ['location_id' => $this->gedungB->id, 'name' => 'Aset A'])->assertOk();
        $this->assertSame($this->a1->id, $this->assetA->refresh()->location_id);
        $this->assertSame('Aset B', $this->assetB->refresh()->name);
        $this->assertNull($this->assetB->archived_at);
    }

    public function test_transfer_checks_both_the_source_and_the_target_location(): void
    {
        $this->loginAs($this->head);

        $this->assertErrorCode($this->transaction($this->assetB, 'transfer', ['to_location_id' => $this->a1->id]), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->transaction($this->assetA, 'transfer', ['to_location_id' => $this->gedungB->id]), 422, 'VALIDATION_FAILED');
        $this->assertSame($this->gedungB->id, $this->assetB->refresh()->location_id);
        $this->assertSame($this->a1->id, $this->assetA->refresh()->location_id);

        $this->transaction($this->assetA, 'transfer', ['to_location_id' => $this->gedungA->id])->assertCreated();
        $this->assertSame($this->gedungA->id, $this->assetA->refresh()->location_id);
    }

    // ------------------------------------------------------------------ tenant isolation

    public function test_another_organizations_records_are_not_reachable_with_a_valid_session(): void
    {
        ['organization' => $beta] = $this->createOrganization('BETA');
        $betaMasters = $this->createMasterFixtures($beta, 'B');
        $betaAsset = $this->createAssetFixture($beta, $betaMasters, 'B');
        $this->loginAs($this->admin);

        $this->getJson("/api/assets/{$betaAsset['asset']->id}")->assertNotFound();
        $this->getJson("/api/transactions/{$betaAsset['transaction']->id}")->assertNotFound();
        $this->assertErrorCode($this->transaction($betaAsset['asset'], 'note', ['notes' => 'x']), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->transaction($this->assetA, 'transfer', ['to_location_id' => $betaMasters['location']->id]), 422, 'VALIDATION_FAILED');
        $this->patchJson("/api/locations/{$betaMasters['location']->id}", ['name' => 'x'])->assertNotFound();
        $this->assertErrorCode($this->patchJson("/api/locations/{$this->a1->id}", ['parent_id' => $betaMasters['location']->id]), 422, 'INVALID_REFERENCE');
        $this->assertErrorCode($this->getJson('/api/assets', ['X-Organization-Id' => $beta->id]), 409, 'ORGANIZATION_MISMATCH');
    }

    public function test_row_level_security_confines_the_raw_subtree_update_used_by_location_moves(): void
    {
        ['organization' => $beta] = $this->createOrganization('BETA');
        $betaLocation = $this->createMasterFixtures($beta, 'B')['location'];
        $betaPath = $betaLocation->path;

        // Same statement shape as MaterializedPath::move, deliberately without the organization filter.
        [$updated, $visible] = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => [
            DB::update('UPDATE locations SET path = path WHERE path LIKE ?', ['%/'.$betaLocation->id.'/%']),
            DB::table('locations')->where('id', $betaLocation->id)->count(),
        ]);

        $this->assertSame(0, $updated);
        $this->assertSame(0, $visible);
        $this->assertSame($betaPath, $betaLocation->refresh()->path);
    }

    // ------------------------------------------------------------------ invitations (SEC-01/02)

    public function test_invitation_scope_is_rechecked_against_the_inviters_current_scope_when_accepted(): void
    {
        Notification::fake();
        $this->customRole('PENGUNDANG', ['user.view', 'user.create', 'role.manage', 'role.view',
            'organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'transaction.view', 'report.view']);
        $inviter = $this->createTenantUser($this->org, ['PENGUNDANG'], $this->scopeAt($this->gedungA));
        $invitee = User::factory()->create(['email' => 'calon@alpha.test', 'email_verified_at' => now()]);
        $this->loginAs($inviter);

        $this->assertErrorCode($this->postJson('/api/invitations', $this->invitation($invitee->email, $this->scopeAt($this->gedungB))), 403, 'SCOPE_ESCALATION');
        $this->postJson('/api/invitations', $this->invitation($invitee->email, $this->scopeAt($this->a1)))->assertCreated();
        $token = $this->sentInvitationToken($invitee->email);

        // The inviter's own scope moves away from Gedung A before the invitation is accepted.
        $this->loginAs($this->admin);
        $this->putJson("/api/users/{$inviter->id}/scopes", ['scopes' => $this->scopeAt($this->gedungB)])->assertOk();

        $this->loginAs($invitee);
        $this->assertErrorCode($this->postJson('/api/auth/invitation/accept', ['token' => $token]), 410, 'INVITATION_UNAVAILABLE');
        $this->assertFalse(DB::table('organization_members')->where('organization_id', $this->org->id)->where('user_id', $invitee->id)->exists());
    }

    // ------------------------------------------------------------------ helpers

    private function loginAs(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    /** @param list<string> $permissions */
    private function customRole(string $code, array $permissions): Role
    {
        $role = (new Role)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $code]);
        $role->save();
        $ids = Permission::query()->where('scope', PermissionCatalog::SCOPE_TENANT)->whereIn('code', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $ids);
        $role->permissions()->attach($ids->mapWithKeys(fn (string $id) => [$id => ['organization_id' => $this->org->id]])->all());

        return $role;
    }

    /**
     * A role manager limited to Gedung A, and a role EDITOR within their permissions.
     *
     * @return array{0: User, 1: Role}
     */
    private function scopedRoleManager(): array
    {
        $this->customRole('PENGELOLA', ['organization.view', 'catalog.generic.view', 'master.tenant.view', 'report.view',
            'user.view', 'user.deactivate', 'role.view', 'role.manage', 'asset.view', 'asset.update', 'transaction.view']);
        $editor = $this->customRole('EDITOR', ['asset.view', 'asset.update']);

        return [$this->createTenantUser($this->org, ['PENGELOLA'], $this->scopeAt($this->gedungA)), $editor];
    }

    /** @return list<array{scope_type: string, ref_id: string}> */
    private function scopeAt(Location $location): array
    {
        return [['scope_type' => 'location', 'ref_id' => $location->id]];
    }

    /** @return list<string> */
    private function scopesOf(User $user): array
    {
        return UserDataScope::query()->where('organization_id', $this->org->id)->where('user_id', $user->id)->get()
            ->map(fn (UserDataScope $s) => $s->scope_type.($s->refId() ? ':'.$s->refId() : ''))->sort()->values()->all();
    }

    private function location(string $code, string $name, ?Location $parent = null): Location
    {
        $location = (new Location)->forceFill([
            'organization_id' => $this->org->id, 'code' => $code, 'name' => $name, 'parent_id' => $parent?->id,
            'location_type_id' => $this->masters['location']->location_type_id,
        ]);
        $location->save();

        return $location->refresh();
    }

    private function asset(string $suffix, Location $location): Asset
    {
        return $this->createAssetFixture($this->org, ['location' => $location, 'category' => $this->masters['category']], $suffix)['asset'];
    }

    /** @return array<string, mixed> */
    private function locationPayload(string $code, ?Location $parent = null): array
    {
        return ['code' => $code, 'name' => "Lokasi {$code}", 'parent_id' => $parent?->id, 'location_type_id' => $this->masters['location']->location_type_id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function transaction(Asset $asset, string $type, array $data = [])
    {
        return $this->postJson('/api/transactions', ['asset_id' => $asset->id, 'type' => $type, ...$data]);
    }

    /**
     * @param  list<array<string, mixed>>  $scopes
     * @return array<string, mixed>
     */
    private function invitation(string $email, array $scopes): array
    {
        return ['email' => $email, 'role_ids' => [$this->roleId($this->org, 'SUPERVISOR')], 'scopes' => $scopes];
    }
}
