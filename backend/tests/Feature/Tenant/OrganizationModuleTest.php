<?php

namespace Tests\Feature\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Optional modules (OrganizationModule::CATALOG): off until the organization switches them on. */
class OrganizationModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Stateful (session) requests: the profile reads the active organization from the session.
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_admin_switches_a_module_on_and_off_with_audit(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $this->login($admin)->assertJsonPath('data.modules', []);

        $this->getJson('/api/modules')->assertOk()
            ->assertJsonPath('data.0.key', 'barcode')->assertJsonPath('data.0.enabled', false);

        $this->putJson('/api/modules/barcode', ['enabled' => true])->assertOk()->assertJsonPath('data.0.enabled', true);
        // Repeating it changes nothing and is not audited again.
        $this->putJson('/api/modules/barcode', ['enabled' => true])->assertOk()->assertJsonPath('data.0.enabled', true);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.modules', ['barcode']);

        $this->putJson('/api/modules/barcode', ['enabled' => false])->assertOk()->assertJsonPath('data.0.enabled', false);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.modules', []);

        $this->assertSame(['module.enabled', 'module.disabled'], AuditLog::query()->where('organization_id', $org->id)
            ->whereIn('action', ['module.enabled', 'module.disabled'])->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    }

    public function test_modules_are_per_organization(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        ['admin' => $other] = $this->createOrganization('BETA');

        $this->actingAs($admin, 'web')->putJson('/api/modules/barcode', ['enabled' => true])->assertOk();

        $this->flushSession();
        $this->actingAs($other, 'web')->getJson('/api/modules')->assertOk()->assertJsonPath('data.0.enabled', false);
    }

    public function test_unknown_module_and_invalid_input_are_rejected(): void
    {
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');

        $this->assertErrorCode($this->putJson('/api/modules/nope', ['enabled' => true]), 404, 'MODULE_NOT_FOUND');
        $this->assertErrorCode($this->putJson('/api/modules/barcode', ['enabled' => 'ya']), 422, 'VALIDATION_FAILED');
    }

    public function test_only_settings_managers_change_modules(): void
    {
        ['organization' => $org, 'admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web')->putJson('/api/modules/barcode', ['enabled' => true])->assertOk();

        $viewer = $this->createTenantUser($org, [$this->viewerRole($org)]);
        $this->flushSession();
        $this->actingAs($viewer, 'web');
        $this->assertErrorCode($this->getJson('/api/modules'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->putJson('/api/modules/barcode', ['enabled' => false]), 403, 'FORBIDDEN');

        // Every member learns which modules are on, through the profile.
        $this->flushSession();
        $this->login($viewer)->assertJsonPath('data.modules', ['barcode']);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }
}
