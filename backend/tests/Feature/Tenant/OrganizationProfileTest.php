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

        $this->putJson('/api/organization', ['name' => 'PT Alpha Baru', 'phone' => '021-5551234', 'code' => 'HACK', 'status' => 'archived'])
            ->assertOk()->assertJsonPath('data.name', 'PT Alpha Baru')->assertJsonPath('data.code', 'ALPHA')->assertJsonPath('data.status', 'active');

        $log = AuditLog::query()->where('action', 'organization.updated')->sole();
        $this->assertSame(['name' => 'Organisasi ALPHA', 'phone' => null], $log->before);
        $this->assertSame(['name' => 'PT Alpha Baru', 'phone' => '0215551234'], $log->after);

        // Settings are system defaults: readable, but there is no endpoint to change them.
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.asset_number_format', 'AST-{YYYY}-{SEQ:6}')->assertJsonPath('data.max_upload_mb', 20);
        $this->putJson('/api/settings', ['max_upload_mb' => 10])->assertStatus(405);
        $this->assertFalse(AuditLog::query()->where('action', 'settings.updated')->where('organization_id', $org->id)->exists());
    }

    public function test_viewer_can_read_profile_but_not_change_it(): void
    {
        ['organization' => $org] = $this->createOrganization('ALPHA');
        $this->actingAs($this->createTenantUser($org, [$this->viewerRole($org)]), 'web');

        $this->getJson('/api/organization')->assertOk();
        $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'X']), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/settings'), 403, 'FORBIDDEN');
    }

    public function test_profile_input_is_normalized(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');

        $this->putJson('/api/organization', [
            'name' => '  PT   Alpha  ',
            'email' => ' Admin@Alpha.CO.ID ',
            'phone' => '+62 812-3456-7890',
            'tax_id' => '01.234.567.8-901.234',
            'currency' => 'idr',
            'legal_name' => '   ',
        ])->assertOk()
            ->assertJsonPath('data.name', 'PT Alpha')
            ->assertJsonPath('data.email', 'admin@alpha.co.id')
            ->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.tax_id', '012345678901234')
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.legal_name', null);

        // The 16-digit (NIK-based) NPWP is accepted too.
        $this->putJson('/api/organization', ['tax_id' => '3201 0123 4567 8901'])->assertOk()->assertJsonPath('data.tax_id', '3201012345678901');
    }

    public function test_profile_rejects_malformed_email_phone_and_npwp(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');

        $this->putJson('/api/organization', ['email' => 'bukan-email', 'phone' => '1111111', 'tax_id' => '12.345'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'phone', 'tax_id'], 'error.details.fields');
        foreach (['0812', '+0123456789', '08123abc456'] as $phone) {
            $this->putJson('/api/organization', ['phone' => $phone])->assertStatus(422)->assertJsonValidationErrors(['phone'], 'error.details.fields');
        }
        $this->putJson('/api/organization', ['tax_id' => '1234567890123'])->assertStatus(422)->assertJsonValidationErrors(['tax_id'], 'error.details.fields');
    }

    public function test_only_super_admin_edits_the_profile_and_every_default_role_reads_it(): void
    {
        ['organization' => $org, 'admin' => $superAdmin] = $this->createOrganization('ALPHA');

        foreach (['SUPERVISOR', 'ADMIN'] as $code) {
            $this->flushSession();
            $this->actingAs($this->createTenantUser($org, [$code]), 'web');
            $this->getJson('/api/organization')->assertOk();
            $this->assertErrorCode($this->putJson('/api/organization', ['name' => 'X']), 403, 'FORBIDDEN');
        }

        $this->flushSession();
        $this->actingAs($superAdmin, 'web');
        $roles = collect($this->getJson('/api/roles')->json('data'))->keyBy('code');
        $this->assertContains('organization.manage', $roles['SUPER_ADMIN']['permissions']);
        foreach (['SUPERVISOR', 'ADMIN'] as $code) {
            $this->assertNotContains('organization.manage', $roles[$code]['permissions']);
            $this->assertContains('organization.view', $roles[$code]['permissions']);
        }

        $this->assertErrorCode($this->postJson('/api/roles', ['name' => 'Pengelola', 'permissions' => ['organization.view', 'organization.manage']]), 422, 'ORGANIZATION_MANAGE_ROLE_ONLY');
        $supervisor = $roles['SUPERVISOR'];
        $this->assertErrorCode($this->patchJson("/api/roles/{$supervisor['id']}", ['permissions' => [...$supervisor['permissions'], 'organization.manage']]), 422, 'ORGANIZATION_MANAGE_ROLE_ONLY');
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
