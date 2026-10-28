<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use Tests\TestCase;

class OrganizationProfileTest extends TestCase
{
    public function test_admin_updates_profile_with_audit_diff_but_not_settings(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');

        $this->putJson('/api/organization', ['name' => 'PT Alpha Baru', 'phone' => '021-555', 'code' => 'HACK', 'status' => 'archived'])
            ->assertOk()->assertJsonPath('data.name', 'PT Alpha Baru')->assertJsonPath('data.code', 'ALPHA')->assertJsonPath('data.status', 'active');

        $log = AuditLog::query()->where('action', 'organization.updated')->sole();
        $this->assertSame(['name' => 'Organisasi ALPHA', 'phone' => null], $log->before);
        $this->assertSame(['name' => 'PT Alpha Baru', 'phone' => '021-555'], $log->after);

        // Settings are system defaults: readable, but there is no endpoint to change them.
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.asset_number_format', 'AST-{YYYY}-{SEQ:6}')->assertJsonPath('data.max_upload_mb', 20);
        $this->putJson('/api/settings', ['max_upload_mb' => 10])->assertStatus(405);
        $this->assertFalse(AuditLog::query()->where('action', 'settings.updated')->where('organization_id', $org->id)->exists());
    }

    public function test_viewer_can_read_profile_but_not_change_it(): void
    {
        ['organization' => $org] = $this->createOrganization('ALPHA');
        $this->actingAs($this->createTenantUser($org, ['SUPERVISOR']), 'web');

        $this->getJson('/api/organization')->assertOk();
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'X']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/settings'), 403, 'FORBIDDEN');
    }

    public function test_health_endpoints(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok'])->assertHeader('X-Request-Id');
        $this->getJson('/api/health/ready')->assertOk()->assertJsonPath('checks.database', 'ok');
    }

    public function test_security_headers_and_request_id_are_present(): void
    {
        $response = $this->withHeader('X-Request-Id', 'client-req-12345')->getJson('/api/health');

        $response->assertHeader('X-Request-Id', 'client-req-12345')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
