<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\MobileAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Identity\Services\SessionTerminator;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\PlatformContext;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * One account logs in once and works in every organization it belongs to. The active
 * organization is kept in the session (see OrganizationAccess) and changed with
 * switchOrganization(); a logged-in user can also create additional organizations.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        [$user, $defaultOrgId] = $this->attempt($credentials);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->tenancy->runAsSystem(fn () => $user->forceFill(['last_login_at' => now()])->save());

        $this->audit->record('auth.login', $user, organizationId: $defaultOrgId, actor: $user);

        return response()->json(['data' => $this->profile($request, $user)]);
    }

    /**
     * Login of the mobile scanner app: issues a read-only bearer token for one organization.
     * An account in several organizations without a usable default gets 409 with the choices,
     * and repeats the login with organization_id.
     */
    public function mobileLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:100'],
            'organization_id' => ['nullable', 'uuid'],
        ]);
        [$user] = $this->attempt(Arr::only($data, ['email', 'password']));

        if (! $user->isTenantUser()) {
            throw ApiException::forbidden('TENANT_USER_REQUIRED', 'Aplikasi mobile hanya untuk pengguna organisasi.');
        }
        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Ganti password Anda melalui aplikasi web terlebih dahulu.');
        }

        $organization = isset($data['organization_id'])
            ? ($this->access->find($user, $data['organization_id'])
                ?? throw ApiException::forbidden('ORGANIZATION_ACCESS_DENIED', 'Anda tidak memiliki akses ke organisasi tersebut.'))
            : $this->access->current(null, $user);
        if ($organization === null) {
            throw ApiException::conflict('ORGANIZATION_SELECTION_REQUIRED', 'Pilih organisasi terlebih dahulu.', [
                'organizations' => $this->access->accessible($user)
                    ->map(fn (Organization $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name])->values()->all(),
            ]);
        }

        $plain = $user->generateTokenString();
        /** @var MobileAccessToken $token */
        $token = $user->tokens()->create([
            'name' => $data['device_name'],
            'token' => hash('sha256', $plain),
            'abilities' => ['read'],
            'expires_at' => now()->addDays(MobileAccessToken::LIFETIME_DAYS),
            'organization_id' => $organization->id,
        ]);
        $this->tenancy->runAsSystem(fn () => $user->forceFill(['last_login_at' => now()])->save());
        $this->audit->record('auth.mobile_login', $user, metadata: ['device_name' => $data['device_name']], organizationId: $organization->id, actor: $user);

        return response()->json(['data' => [
            'token' => $token->getKey().'|'.$plain,
            'expires_at' => $token->expires_at->toIso8601String(),
            ...$this->mobileProfile($user, $organization),
        ]]);
    }

    /** Profile of the mobile app's account in the token's organization. */
    public function mobileMe(Request $request): JsonResponse
    {
        $token = $this->mobileToken($request);
        $user = $this->account($request);
        $organization = $user->isActive() ? $this->access->find($user, $token->organization_id) : null;
        if ($organization === null) {
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun atau akses organisasi Anda tidak aktif.', 401);
        }

        return response()->json(['data' => $this->mobileProfile($user, $organization)]);
    }

    /** Revokes the token the mobile app is using. */
    public function mobileLogout(Request $request): Response
    {
        $token = $this->mobileToken($request);
        $this->audit->record('auth.mobile_logout', $request->user(), metadata: ['device_name' => $token->name], organizationId: $token->organization_id, actor: $request->user());
        $token->delete();

        return response()->noContent();
    }

    /**
     * Checks the credentials of an active account that can work somewhere; failures are audited.
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{0: User, 1: string|null} the account and its default organization id
     */
    private function attempt(#[\SensitiveParameter] array $credentials): array
    {
        /** @var User|null $user */
        $user = $this->tenancy->runAsSystem(fn () => User::query()->where('email', $credentials['email'])->first());
        // Always run a hash check so response time does not reveal whether the email exists.
        $passwordOk = Hash::check($credentials['password'], $user?->password ?? '$2y$12$'.str_repeat('a', 53));
        $defaultOrgId = $user === null ? null : $this->access->current(null, $user)?->id;

        if ($user === null || ! $passwordOk || ! $user->isActive()) {
            $this->audit->record('auth.login_failed', $user, metadata: [
                'email' => $credentials['email'],
                'reason' => $user === null ? 'unknown_email' : (! $passwordOk ? 'bad_password' : 'inactive_account'),
            ], organizationId: $defaultOrgId);

            throw ApiException::unprocessable('INVALID_CREDENTIALS', 'Email atau password salah.');
        }

        if ($user->isTenantUser() && $this->access->accessible($user)->isEmpty()) {
            $this->audit->record('auth.login_failed', $user, metadata: ['reason' => 'organization_inactive'], actor: $user);
            throw ApiException::forbidden('ORGANIZATION_INACTIVE', 'Organisasi Anda sedang tidak aktif.');
        }

        return [$user, $defaultOrgId];
    }

    private function mobileToken(Request $request): MobileAccessToken
    {
        $token = $request->user()->currentAccessToken();
        if (! $token instanceof MobileAccessToken) {
            throw ApiException::forbidden('MOBILE_TOKEN_REQUIRED', 'Endpoint ini hanya untuk aplikasi mobile.');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function mobileProfile(User $user, Organization $organization): array
    {
        return $this->tenancy->runAsTenant(new TenantContext($organization, $user), fn () => [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'job_title' => $user->membership()->value('job_title')],
            'organization' => ['id' => $organization->id, 'code' => $organization->code, 'name' => $organization->name],
            'permissions' => $this->tenancy->tenant()->permissions(),
        ]);
    }

    /**
     * Self-service sign-up: creates a new account and a new organization with the registrant
     * as its Organization Administrator (same provisioning as the platform console), then
     * logs in. Existing accounts create further organizations with createOrganization().
     * Runs as system so the uniqueness checks and provisioning see every organization.
     */
    public function register(Request $request, OrganizationProvisioner $provisioner, Licensing $licensing): JsonResponse
    {
        $this->assertSelfServiceEnabled();

        $request->merge(['organization_code' => strtoupper((string) $request->input('organization_code'))]);
        $result = $this->tenancy->runAsSystem(function () use ($request, $provisioner, $licensing) {
            $data = $request->validate([
                ...$this->organizationRules(),
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
                'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
                'license_quantity' => ['nullable', 'integer', 'min:0', 'max:100'],
            ], [
                'organization_code.unique' => 'Kode organisasi sudah dipakai.',
                'email.unique' => 'Email sudah terdaftar. Silakan masuk, lalu buat organisasi baru dari menu organisasi.',
            ]);

            return DB::transaction(function () use ($provisioner, $licensing, $data) {
                $result = $provisioner->provision(
                    ['code' => $data['organization_code'], 'name' => $data['organization_name']],
                    ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
                    adminMustChangePassword: false,
                );
                // The SUPER_ADMIN_FREE license code goes to the admin automatically.
                $licensing->grantTrial($result['organization'], $result['admin']);

                // Purchase additional licenses and create license codes if requested
                if (isset($data['license_quantity']) && $data['license_quantity'] > 0) {
                    $quantity = $data['license_quantity'];
                    $start = now();
                    $end = $start->copy()->addYears(1);

                    for ($i = 0; $i < $quantity; $i++) {
                        $reference = 'REGISTRATION-' . date('Ymd') . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
                        $licensing->grant(
                            organization: $result['organization'],
                            kind: \App\Domain\Licensing\Models\OrganizationLicense::KIND_BASE,
                            seats: 1,
                            startsAt: $start,
                            expiresAt: $end,
                            reference: $reference,
                            notes: 'Pembelian lisensi saat registrasi',
                            source: \App\Domain\Licensing\Models\OrganizationLicense::SOURCE_PURCHASE,
                        );

                        // Create license code for each purchased license with reference
                        try {
                            $licenseCode = \App\Domain\Licensing\Services\LicenseCodeService::createForOrganization(
                                $result['organization'],
                                'Lisensi dari pembelian saat registrasi',
                                $reference
                            );
                            \Log::info('License code created during registration', [
                                'reference' => $reference,
                                'code' => $licenseCode->code,
                                'organization_id' => $result['organization']->id,
                            ]);
                        } catch (\Exception $e) {
                            \Log::error('Failed to create license code during registration', [
                                'error' => $e->getMessage(),
                                'reference' => $reference,
                                'organization_id' => $result['organization']->id,
                            ]);
                        }
                    }
                }

                return $result;
            });
        });
        $user = $result['admin'];

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $this->access->switch($request->session(), $user, $result['organization']);
        }
        $this->tenancy->runAsSystem(fn () => $user->forceFill(['last_login_at' => now()])->save());

        $this->audit->record('auth.registered', $user, organizationId: $result['organization']->id, actor: $user);
        // The address is unproven until its owner follows the link (required to accept invitations).
        $user->sendEmailVerificationNotification();

        return response()->json(['data' => $this->profile($request, $user)], 201);
    }

    /** A logged-in tenant user creates another organization (same account) and switches to it. */
    public function createOrganization(Request $request, OrganizationProvisioner $provisioner, Licensing $licensing): JsonResponse
    {
        $this->assertSelfServiceEnabled();
        $user = $this->freshTenantUser($request);

        $request->merge(['organization_code' => strtoupper((string) $request->input('organization_code'))]);
        $organization = $this->tenancy->runAsSystem(function () use ($request, $provisioner, $licensing, $user) {
            $data = $request->validate($this->organizationRules(), ['organization_code.unique' => 'Kode organisasi sudah dipakai.']);

            return DB::transaction(function () use ($provisioner, $licensing, $data, $user) {
                $organization = $provisioner->provision(
                    ['code' => $data['organization_code'], 'name' => $data['organization_name']],
                    $user,
                )['organization'];
                $licensing->grantTrial($organization, $user);

                return $organization;
            });
        });

        $this->access->switch($request->session(), $user, $organization);
        $this->audit->record('auth.organization_created', $organization, organizationId: $organization->id, actor: $user);

        return response()->json(['data' => $this->profile($request, $user)], 201);
    }

    /** Changes the active organization of this session to another organization of the account. */
    public function switchOrganization(Request $request): JsonResponse
    {
        $user = $this->freshTenantUser($request);
        $data = $request->validate(['organization_id' => ['required', 'uuid']]);

        $organization = $this->access->find($user, $data['organization_id'])
            ?? throw ApiException::forbidden('ORGANIZATION_ACCESS_DENIED', 'Anda tidak memiliki akses ke organisasi tersebut.');

        $this->access->switch($request->session(), $user, $organization);
        $this->audit->record('auth.organization_switched', $organization, organizationId: $organization->id, actor: $user);

        return response()->json(['data' => $this->profile($request, $user)]);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->audit->record('auth.logout', $user, organizationId: $this->currentOrganizationId($request, $user), actor: $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->account($request);

        if (! $user->isActive()) {
            Auth::guard('web')->setUser($user);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }

        return response()->json(['data' => $this->profile($request, $user)]);
    }

    public function changePassword(Request $request, SessionTerminator $sessions): JsonResponse
    {
        /** @var User $user */
        $user = $this->account($request);

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'max:200',
                Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $this->tenancy->runAsSystem(fn () => $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save());

        $request->session()->regenerate();
        $sessions->terminateAll($user, exceptSessionId: $request->session()->getId());
        $this->audit->record('auth.password_changed', $user, organizationId: $this->currentOrganizationId($request, $user), actor: $user);

        return response()->json(['data' => $this->profile($request, $user)]);
    }

    private function assertSelfServiceEnabled(): void
    {
        if (! config('auth.self_registration')) {
            throw ApiException::forbidden('REGISTRATION_DISABLED', 'Pendaftaran mandiri tidak tersedia. Hubungi administrator.');
        }
    }

    /** @return array<string, list<mixed>> */
    private function organizationRules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:150'],
            'organization_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,29}$/', Rule::unique('organizations', 'code')],
        ];
    }

    /** Fresh read of the logged-in account. These routes have no context, and RLS protects accounts (SEC-07). */
    private function account(Request $request): User
    {
        return $this->tenancy->runAsSystem(fn () => User::query()->findOrFail($request->user()->getKey()));
    }

    private function freshTenantUser(Request $request): User
    {
        /** @var User $user */
        $user = $this->account($request);
        if (! $user->isActive()) {
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }
        if (! $user->isTenantUser()) {
            throw ApiException::forbidden('TENANT_USER_REQUIRED', 'Endpoint ini hanya untuk pengguna organisasi.');
        }
        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Anda harus mengganti password sebelum melanjutkan.');
        }

        return $user;
    }

    private function currentOrganizationId(Request $request, User $user): ?string
    {
        return $user->isTenantUser() ? $this->access->current($request->session(), $user)?->id : null;
    }

    /** @return array<string, mixed> */
    private function profile(Request $request, User $user): array
    {
        $base = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'user_type' => $user->user_type,
                'job_title' => null,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'organization' => null,
            'organizations' => [],
            'permissions' => [],
            'data_scope' => null,
        ];

        if ($user->isPlatformUser()) {
            $platform = new PlatformContext($user);
            $base['permissions'] = $this->tenancy->runAsPlatform($platform, fn () => $platform->permissions());

            return $base;
        }

        $base['organizations'] = $this->access->accessible($user)
            ->map(fn (Organization $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name])
            ->values()->all();

        if ($request->hasSession()) {
            $session = $request->session();
            $organization = $this->access->current($session, $user);
            if ($organization === null && $session->has(OrganizationAccess::SESSION_KEY)) {
                // The active organization is no longer accessible: fall back to the automatic choice.
                $this->access->forget($session);
                $organization = $this->access->current($session, $user);
            }
            if ($organization === null) {
                return $base;
            }
            $base['organization'] = (new OrganizationResource($organization))->resolve();
        } else {
            return $base;
        }

        return $this->tenancy->runAsTenant(new TenantContext($organization, $user), function () use ($base, $user) {
            $context = $this->tenancy->tenant();
            $base['user']['job_title'] = $user->membership()->value('job_title');
            $base['permissions'] = $context->permissions();
            $base['data_scope'] = $context->scope()->toArray();

            return $base;
        });
    }
}
