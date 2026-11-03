<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mobile scanner app: a read-only bearer token bound to one organization, and the lookup of
 * a scanned barcode (the asset number) to the asset's detail.
 */
class MobileAccessTest extends TestCase
{
    private Organization $alpha;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->alpha, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->alpha);
        $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin), fn () => $this->createAssetFixture($this->alpha, $this->masters));
        $this->enableModule($this->alpha, OrganizationModule::BARCODE);
    }

    public function test_lookup_needs_the_barcode_module(): void
    {
        ['organization' => $beta, 'admin' => $admin] = $this->createOrganization('BETA');
        $token = $this->login(['email' => $admin->email])->assertOk()->assertJsonPath('data.modules', [])->json('data.token');

        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1'), 403, 'MODULE_DISABLED');

        $this->enableModule($beta, OrganizationModule::BARCODE);
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertOk()->assertJsonPath('data.modules', ['barcode']);
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1'), 404, 'ASSET_NOT_FOUND');
    }

    public function test_login_issues_token_that_looks_up_scanned_assets(): void
    {
        $login = $this->login()->assertOk()
            ->assertJsonPath('data.organization.code', 'ALPHA')
            ->assertJsonPath('data.user.email', $this->admin->email);
        $this->assertContains('asset.view', $login->json('data.permissions'));
        $token = $login->json('data.token');

        $this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1')->assertOk()
            ->assertJsonPath('data.asset_number', 'FIX-1')
            ->assertJsonPath('data.category.code', 'CAT1')
            ->assertJsonStructure(['data' => ['attributes', 'barcode_enabled'], 'attribute_fields']);
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=NOPE-9'), 404, 'ASSET_NOT_FOUND');
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertOk()->assertJsonPath('data.organization.id', $this->alpha->id);

        $this->assertTrue(AuditLog::query()->where('action', 'auth.mobile_login')->where('organization_id', $this->alpha->id)->exists());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->assertErrorCode($this->login(['password' => 'wrong-password']), 422, 'INVALID_CREDENTIALS');
    }

    public function test_token_only_reads(): void
    {
        $token = $this->login()->json('data.token');

        $this->assertErrorCode($this->bearer($token)->postJson('/api/assets', ['name' => 'X']), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson('/api/auth/organizations', ['organization_name' => 'X', 'organization_code' => 'XX']), 403, 'SESSION_REQUIRED');
        $this->assertErrorCode($this->bearer($token)->putJson('/api/auth/password', []), 403, 'SESSION_REQUIRED');
    }

    public function test_account_in_several_organizations_chooses_one_per_token(): void
    {
        ['organization' => $beta] = $this->createOrganization('BETA');
        $this->addMembership($this->admin, $beta, $this->viewerRole($beta));

        $choice = $this->login();
        $this->assertErrorCode($choice, 409, 'ORGANIZATION_SELECTION_REQUIRED');
        $this->assertEqualsCanonicalizing(['ALPHA', 'BETA'], array_column($choice->json('error.details.organizations'), 'code'));

        $this->enableModule($beta, OrganizationModule::BARCODE);
        $token = $this->login(['organization_id' => $beta->id])->assertOk()->assertJsonPath('data.organization.code', 'BETA')->json('data.token');
        // ALPHA's asset is not visible through a BETA token.
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1'), 404, 'ASSET_NOT_FOUND');

        ['organization' => $gamma] = $this->createOrganization('GAMMA');
        $this->assertErrorCode($this->login(['organization_id' => $gamma->id]), 403, 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_revoked_membership_and_logout_end_the_token(): void
    {
        $token = $this->login()->json('data.token');
        OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->admin->id)->sole()
            ->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1'), 403, 'ORGANIZATION_INACTIVE');

        OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->admin->id)->sole()
            ->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->bearer($token)->postJson('/api/auth/mobile/logout')->assertNoContent();
        $this->bearer($token)->getJson('/api/assets/lookup?code=FIX-1')->assertUnauthorized();
        $this->bearer('not-a-token')->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_tokens_are_invisible_in_a_tenant_context(): void
    {
        $this->login()->assertOk();

        $this->assertSame(1, DB::table('mobile_access_tokens')->count());
        $this->assertSame(0, $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin), fn () => DB::table('mobile_access_tokens')->count()));
    }

    /** @param array<string, mixed> $overrides */
    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/auth/mobile/login', [
            'email' => $this->admin->email, 'password' => self::PASSWORD, 'device_name' => 'Pixel 9', ...$overrides,
        ]);
    }

    /** Guards cache the user between requests of one test; every app request starts fresh in production. */
    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
