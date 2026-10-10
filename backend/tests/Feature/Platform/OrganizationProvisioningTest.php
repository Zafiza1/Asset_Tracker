<?php

namespace Tests\Feature\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use Tests\TestCase;

class OrganizationProvisioningTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'code' => 'gamma',
            'name' => 'PT Gamma Sejahtera',
            'admin' => ['name' => 'Admin Gamma', 'email' => 'admin@gamma.test', 'password' => 'Awal-Rahasia-2026'],
        ], $overrides);
    }

    public function test_platform_admin_provisions_a_complete_organization(): void
    {
        $platform = $this->createPlatformAdmin();
        $this->actingAs($platform, 'web');

        $response = $this->postJson('/api/platform/organizations', $this->payload());

        $response->assertCreated()->assertJsonPath('data.code', 'GAMMA')->assertJsonPath('data.status', 'active');
        $org = Organization::query()->where('code', 'GAMMA')->sole();
        $admin = User::query()->findOrFail($response->json('meta.admin_user_id'));

        $this->assertSame($org->id, $admin->organization_id);
        $this->assertTrue($admin->must_change_password);
        $this->assertSame(['ORG_ADMIN'], $admin->roles()->pluck('code')->all());
        $this->assertSame(['organization'], $admin->dataScopes()->pluck('scope_type')->all());
        $this->assertSame(6, Role::query()->where('organization_id', $org->id)->count());
        $this->assertTrue(OrganizationSetting::query()->where('organization_id', $org->id)->exists());

        $log = AuditLog::query()->where('action', 'organization.created')->sole();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame($platform->id, $log->actor_user_id);
    }

    public function test_provisioned_admin_can_log_in_and_must_change_password(): void
    {
        $this->actingAs($this->createPlatformAdmin(), 'web');
        $this->postJson('/api/platform/organizations', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->logout();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', ['email' => 'admin@gamma.test', 'password' => 'Awal-Rahasia-2026'])
            ->assertOk()->assertJsonPath('data.user.must_change_password', true)
            ->assertJsonPath('data.organization.code', 'GAMMA');
    }

    public function test_provisioning_is_atomic(): void
    {
        ['admin' => $existing] = $this->createOrganization('ALPHA');
        $this->actingAs($this->createPlatformAdmin(), 'web');

        // Admin email already used: validation stops it, nothing is created.
        $this->postJson('/api/platform/organizations', $this->payload(['admin' => ['email' => $existing->email]]))->assertStatus(422);
        $this->assertFalse(Organization::query()->where('code', 'GAMMA')->exists());

        $this->postJson('/api/platform/organizations', $this->payload(['code' => 'alpha']))->assertStatus(422);
    }

    public function test_platform_permission_is_required(): void
    {
        $this->actingAs($this->createPlatformAdmin(withRole: false), 'web');

        $this->assertErrorCode($this->getJson('/api/platform/organizations'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->postJson('/api/platform/organizations', $this->payload()), 403, 'FORBIDDEN');
    }

    public function test_suspending_an_organization_requires_reason_blocks_its_users_and_is_audited(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $platform = $this->createPlatformAdmin();
        $this->actingAs($platform, 'web');

        $this->postJson("/api/platform/organizations/{$org->id}/suspend")->assertStatus(422);
        $this->postJson("/api/platform/organizations/{$org->id}/suspend", ['reason' => 'Tunggakan kontrak'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $log = AuditLog::query()->where('action', 'organization.status_changed')->sole();
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame('Tunggakan kontrak', $log->metadata['reason']);

        $this->actingAs($admin, 'web');
        $this->assertErrorCode($this->getJson('/api/organization'), 403, 'ORGANIZATION_INACTIVE');
    }

    public function test_platform_lists_organizations_and_platform_audit(): void
    {
        $this->createOrganization('ALPHA');
        $this->createOrganization('BETA');
        $this->actingAs($this->createPlatformAdmin(), 'web');

        $this->getJson('/api/platform/organizations?search=alp')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALPHA');

        $orgId = Organization::query()->where('code', 'BETA')->value('id');
        $actions = array_column($this->getJson("/api/platform/audit-logs?organization_id={$orgId}")->assertOk()->json('data'), 'action');
        $this->assertContains('organization.created', $actions);
    }
}
