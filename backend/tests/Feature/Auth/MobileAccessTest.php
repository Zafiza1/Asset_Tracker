<?php

namespace Tests\Feature\Auth;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\MobileAccessToken;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mobile app: a bearer token bound to one organization (reads and a few listed writes) and the lookup of
 * a scanned barcode (the asset number) to the asset's detail.
 */
class MobileAccessTest extends TestCase
{
    private Organization $alpha;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->alpha, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->alpha);
        $this->asset = $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin), fn () => $this->createAssetFixture($this->alpha, $this->masters))['asset'];
        $this->enableModule($this->alpha, OrganizationModule::BARCODE);
    }

    public function test_lookup_needs_the_barcode_module(): void
    {
        ['organization' => $beta, 'admin' => $admin] = $this->createOrganization('BETA');
        $token = $this->login(['email' => $admin->email])->assertOk()->assertJsonPath('data.modules', [])->json('data.token');

        $this->assertErrorCode($this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}"), 403, 'MODULE_DISABLED');

        $this->enableModule($beta, OrganizationModule::BARCODE);
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertOk()->assertJsonPath('data.modules', ['barcode']);
        $this->assertErrorCode($this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}"), 404, 'ASSET_NOT_FOUND');
    }

    public function test_login_issues_token_that_looks_up_scanned_assets(): void
    {
        $login = $this->login()->assertOk()
            ->assertJsonPath('data.organization.code', 'ALPHA')
            ->assertJsonPath('data.user.email', $this->admin->email);
        $this->assertContains('asset.view', $login->json('data.permissions'));
        $token = $login->json('data.token');

        $this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}")->assertOk()
            ->assertJsonPath('data.id', $this->asset->id)
            ->assertJsonMissingPath('data.asset_number')
            ->assertJsonPath('data.category.code', 'CAT1')
            ->assertJsonStructure(['data' => ['attributes', 'barcode_enabled'], 'attribute_fields']);
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=NOPE-9'), 404, 'ASSET_NOT_FOUND');
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code=00000000-0000-4000-8000-000000000000'), 404, 'ASSET_NOT_FOUND');
        // Typed in by hand: the serial number (any case) finds the asset, the internal asset number does not.
        $this->bearer($token)->getJson('/api/assets/lookup?code=sn-fix-1')->assertOk()->assertJsonPath('data.id', $this->asset->id);
        $number = $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin), fn () => $this->asset->refresh()->asset_number);
        $this->assertErrorCode($this->bearer($token)->getJson('/api/assets/lookup?code='.urlencode($number)), 404, 'ASSET_NOT_FOUND');
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertOk()->assertJsonPath('data.organization.id', $this->alpha->id);

        $this->assertTrue(AuditLog::query()->where('action', 'auth.mobile_login')->where('organization_id', $this->alpha->id)->exists());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->assertErrorCode($this->login(['password' => 'wrong-password']), 422, 'INVALID_CREDENTIALS');
    }

    public function test_token_writes_only_the_listed_routes(): void
    {
        $token = $this->login()->json('data.token');
        $asset = $this->asset;

        $this->assertErrorCode($this->bearer($token)->patchJson("/api/assets/{$asset->id}", ['name' => 'X']), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson("/api/assets/{$asset->id}/archive"), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson('/api/assets/import'), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->patchJson("/api/locations/{$this->masters['location']->id}", ['name' => 'X']), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson('/api/users/'.$this->admin->id.'/deactivate'), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson('/api/auth/organizations', ['organization_name' => 'X', 'organization_code' => 'XX']), 403, 'SESSION_REQUIRED');
        $this->assertErrorCode($this->bearer($token)->putJson('/api/auth/password', []), 403, 'SESSION_REQUIRED');
    }

    public function test_approver_registers_assets_locations_and_categories_directly(): void
    {
        $token = $this->login()->json('data.token');

        $location = $this->bearer($token)->postJson('/api/locations', ['name' => 'Gudang Mobile'])->assertCreated()->json('data');
        $category = $this->bearer($token)->postJson('/api/tenant-masters/categories', ['name' => 'Kategori Mobile'])->assertCreated()->json('data');
        // A new category has the default fields, with the required Kondisi Aset, as on the web.
        $fields = $this->bearer($token)->getJson("/api/tenant-masters/categories/{$category['id']}/attributes")->assertOk()->json('data');
        $condition = collect($fields)->firstWhere('label', 'Kondisi Aset');
        $this->assertTrue($condition['is_required']);
        $this->bearer($token)->postJson('/api/assets', [
            'name' => 'Laptop Lapangan', 'category_id' => $category['id'], 'location_id' => $location['id'], 'status' => 'in_use',
        ])->assertStatus(422);
        $this->bearer($token)->postJson('/api/assets', [
            'name' => 'Laptop Lapangan', 'category_id' => $category['id'], 'location_id' => $location['id'], 'status' => 'in_use',
            'attributes' => [$condition['key'] => 'BAIK'],
        ])->assertCreated()->assertJsonPath('data.name', 'Laptop Lapangan')->assertJsonPath('data.location.id', $location['id']);
    }

    public function test_changes_without_approval_permission_wait_for_an_approver_on_mobile(): void
    {
        $staff = $this->createTenantUser($this->alpha, ['ADMIN']);
        $staffToken = $this->login(['email' => $staff->email])->assertOk()->json('data.token');
        $this->assertNotContains('approval.manage', $this->bearer($staffToken)->getJson('/api/auth/mobile/me')->json('data.permissions'));

        $pending = $this->bearer($staffToken)->postJson('/api/assets', [
            'name' => 'Proyektor', 'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertStatus(202)->assertJsonPath('meta.approval_pending', true)->assertJsonPath('data.status', 'pending');
        $locationRequest = $this->bearer($staffToken)->postJson('/api/locations', ['name' => 'Ruang Rapat'])->assertStatus(202)->json('data.id');
        $this->bearer($staffToken)->postJson('/api/tenant-masters/categories', ['name' => 'Elektronik'])->assertStatus(202);
        $this->assertFalse($this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin),
            fn () => Asset::query()->where('name', 'Proyektor')->exists()));

        // The requester follows (and may withdraw) their own requests, but cannot decide.
        $this->bearer($staffToken)->getJson('/api/approvals?status=pending')->assertOk()->assertJsonCount(3, 'data');
        $this->assertErrorCode($this->bearer($staffToken)->postJson("/api/approvals/{$pending['data']['id']}/approve"), 403, 'FORBIDDEN');
        $this->bearer($staffToken)->postJson("/api/approvals/{$locationRequest}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $approverToken = $this->login()->json('data.token');
        $this->bearer($approverToken)->getJson("/api/approvals/{$pending['data']['id']}")->assertOk()->assertJsonStructure(['data' => ['details', 'impact', 'history']]);
        $this->bearer($approverToken)->postJson("/api/approvals/{$pending['data']['id']}/approve", ['note' => 'OK'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin),
            fn () => Asset::query()->where('name', 'Proyektor')->exists()));

        $categoryRequest = $this->bearer($approverToken)->getJson('/api/approvals?status=pending')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->assertErrorCode($this->bearer($approverToken)->postJson("/api/approvals/{$categoryRequest}/reject", ['note' => '']), 422, 'VALIDATION_FAILED');
        $this->bearer($approverToken)->postJson("/api/approvals/{$categoryRequest}/reject", ['note' => 'Sudah ada kategori serupa'])->assertOk()->assertJsonPath('data.status', 'rejected');
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
        $this->assertErrorCode($this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}"), 404, 'ASSET_NOT_FOUND');

        ['organization' => $gamma] = $this->createOrganization('GAMMA');
        $this->assertErrorCode($this->login(['organization_id' => $gamma->id]), 403, 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_revoked_membership_and_logout_end_the_token(): void
    {
        $token = $this->login()->json('data.token');
        OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->admin->id)->sole()
            ->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        $this->assertErrorCode($this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}"), 403, 'ORGANIZATION_INACTIVE');

        OrganizationMember::query()->where('organization_id', $this->alpha->id)->where('user_id', $this->admin->id)->sole()
            ->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->bearer($token)->postJson('/api/auth/mobile/logout')->assertNoContent();
        $this->bearer($token)->getJson("/api/assets/lookup?code={$this->asset->id}")->assertUnauthorized();
        $this->bearer('not-a-token')->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_transaction_write_ability_records_outgoing_and_incoming_only(): void
    {
        $login = $this->login()->assertOk()->assertJsonPath('data.abilities', ['read', 'transaction:write']);
        $token = $login->json('data.token');
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertJsonPath('data.abilities', ['read', 'transaction:write']);
        $asset = $this->asset->id;

        $out = $this->bearer($token)->postJson('/api/transactions', [
            'asset_ids' => [$asset], 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis', 'due_date' => now()->addWeek()->toDateString(),
        ])->assertCreated()->json('data.0');
        $this->assertStringStartsWith('OUT-', $out['transaction_number']);
        $this->bearer($token)->postJson('/api/transactions', ['asset_ids' => [$asset], 'type' => 'incoming'])
            ->assertCreated()->assertJsonPath('data.0.outgoing_transaction_id', $out['id']);

        // Other transaction types stay on the web.
        $this->assertErrorCode($this->bearer($token)->postJson('/api/transactions', ['asset_ids' => [$asset], 'type' => 'note', 'notes' => 'x']), 403, 'MOBILE_TOKEN_READ_ONLY');
        $this->assertErrorCode($this->bearer($token)->postJson('/api/transactions', ['asset_ids' => [$asset], 'type' => 'transfer']), 403, 'MOBILE_TOKEN_READ_ONLY');
    }

    public function test_token_without_transaction_permissions_gets_no_write_ability(): void
    {
        $viewer = $this->createTenantUser($this->alpha, [$this->createRole($this->alpha, 'MOBILE_VIEW', ['asset.view', 'transaction.view', 'transaction.create'])->code]);
        $token = $this->login(['email' => $viewer->email])->assertOk()->assertJsonPath('data.abilities', ['read'])->json('data.token');

        $this->assertErrorCode($this->bearer($token)->postJson('/api/transactions', [
            'asset_ids' => [$this->asset->id], 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis', 'due_date' => now()->addWeek()->toDateString(),
        ]), 403, 'MOBILE_TOKEN_READ_ONLY');
    }

    public function test_revoked_permission_blocks_a_token_that_still_has_the_ability(): void
    {
        $role = $this->createRole($this->alpha, 'MOBILE_OUT', ['asset.view', 'transaction.view', 'transaction.create', 'asset.outgoing', 'approval.manage']);
        $staff = $this->createTenantUser($this->alpha, [$role->code]);
        $token = $this->login(['email' => $staff->email])->assertOk()->assertJsonPath('data.abilities', ['read', 'transaction:write'])->json('data.token');

        $this->tenancy()->runAsTenant(new TenantContext($this->alpha, $this->admin), fn () => $role->permissions()->detach(
            $role->permissions()->where('code', 'asset.outgoing')->pluck('permissions.id')
        ));
        $response = $this->bearer($token)->postJson('/api/transactions', [
            'asset_ids' => [$this->asset->id], 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis', 'due_date' => now()->addWeek()->toDateString(),
        ]);
        $this->assertErrorCode($response, 403, 'FORBIDDEN');
    }

    public function test_token_works_until_the_end_of_the_organization_day(): void
    {
        $jakarta = fn (string $at) => CarbonImmutable::parse($at, 'Asia/Jakarta');
        $end = fn (string $at) => MobileAccessToken::expiresAt('Asia/Jakarta', $jakarta($at))->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
        $this->assertSame('2026-11-17 23:59:59', $end('2026-11-17 08:00'));
        $this->assertSame('2026-11-17 23:59:59', $end('2026-11-17 17:59'));
        // Under MIN_HOURS left (e.g. a night shift): the end of the next day, so it never ends mid-shift.
        $this->assertSame('2026-11-18 23:59:59', $end('2026-11-17 21:30'));
        $this->assertSame('2026-11-18 23:59:59', $end('2026-11-18 00:30'));
        // The organization's day, not the server's (UTC).
        $this->assertSame('2026-11-17 23:59:59', MobileAccessToken::expiresAt(null, CarbonImmutable::parse('2026-11-17 08:00', 'UTC'))->format('Y-m-d H:i:s'));

        $this->tenancy()->runAsSystem(fn () => $this->alpha->forceFill(['timezone' => 'Asia/Jakarta'])->save());
        $login = $this->login()->assertOk();
        $this->assertSame(MobileAccessToken::expiresAt('Asia/Jakarta')->toIso8601String(), $login->json('data.expires_at'));

        // Past its end the token is refused and the app asks to log in again.
        $token = $login->json('data.token');
        $this->tenancy()->runAsSystem(fn () => DB::table('mobile_access_tokens')->update(['expires_at' => now()->subSecond()]));
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_password_change_and_reset_end_mobile_logins(): void
    {
        $token = $this->login()->json('data.token');
        $this->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($this->admin, 'web');
        $this->putJson('/api/auth/password', [
            'current_password' => self::PASSWORD, 'password' => 'Baru-Rahasia-2026', 'password_confirmation' => 'Baru-Rahasia-2026',
        ])->assertOk();
        $this->assertSame(0, $this->tenancy()->runAsSystem(fn () => DB::table('mobile_access_tokens')->where('tokenable_id', $this->admin->id)->count()));
        $this->bearer($token)->getJson('/api/auth/mobile/me')->assertUnauthorized();

        // Reset by an administrator.
        $staff = $this->createTenantUser($this->alpha, ['ADMIN']);
        $staffToken = $this->login(['email' => $staff->email])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($this->admin, 'web');
        $this->postJson("/api/users/{$staff->id}/reset-password", ['password' => 'Sementara-Rahasia-2026'])->assertNoContent();
        $this->assertSame(0, $this->tenancy()->runAsSystem(fn () => DB::table('mobile_access_tokens')->where('tokenable_id', $staff->id)->count()));
        $this->bearer($staffToken)->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_deactivated_member_loses_mobile_logins_of_that_organization(): void
    {
        $staff = $this->createTenantUser($this->alpha, ['ADMIN']);
        $this->login(['email' => $staff->email])->assertOk();
        $this->assertSame(1, $this->tenancy()->runAsSystem(fn () => DB::table('mobile_access_tokens')->where('tokenable_id', $staff->id)->count()));

        $this->withoutToken()->withHeader('Origin', 'http://localhost:5173')->actingAs($this->admin, 'web');
        $this->postJson("/api/users/{$staff->id}/deactivate")->assertOk();
        $this->assertSame(0, $this->tenancy()->runAsSystem(fn () => DB::table('mobile_access_tokens')->where('tokenable_id', $staff->id)->count()));
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
