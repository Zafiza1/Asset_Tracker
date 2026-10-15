<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Identity\Services\SessionTerminator;
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
use Illuminate\Support\Facades\Auth;
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

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();
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

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login', $user, organizationId: $defaultOrgId, actor: $user);

        return response()->json(['data' => $this->profile($request, $user)]);
    }

    /**
     * Self-service sign-up: creates a new account and a new organization with the registrant
     * as its Organization Administrator (same provisioning as the platform console), then
     * logs in. Existing accounts create further organizations with createOrganization().
     * Runs as system so the uniqueness checks and provisioning see every organization.
     */
    public function register(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        $this->assertSelfServiceEnabled();

        $request->merge(['organization_code' => strtoupper((string) $request->input('organization_code'))]);
        $result = $this->tenancy->runAsSystem(function () use ($request, $provisioner) {
            $data = $request->validate([
                ...$this->organizationRules(),
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
                'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
            ], [
                'organization_code.unique' => 'Kode organisasi sudah dipakai.',
                'email.unique' => 'Email sudah terdaftar. Silakan masuk, lalu buat organisasi baru dari menu organisasi.',
            ]);

            return $provisioner->provision(
                ['code' => $data['organization_code'], 'name' => $data['organization_name']],
                ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
                adminMustChangePassword: false,
            );
        });
        $user = $result['admin'];

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->access->switch($request->session(), $user, $result['organization']);

        $this->audit->record('auth.registered', $user, organizationId: $result['organization']->id, actor: $user);

        return response()->json(['data' => $this->profile($request, $user)], 201);
    }

    /** A logged-in tenant user creates another organization (same account) and switches to it. */
    public function createOrganization(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        $this->assertSelfServiceEnabled();
        $user = $this->freshTenantUser($request);

        $request->merge(['organization_code' => strtoupper((string) $request->input('organization_code'))]);
        $organization = $this->tenancy->runAsSystem(function () use ($request, $provisioner, $user) {
            $data = $request->validate($this->organizationRules(), ['organization_code.unique' => 'Kode organisasi sudah dipakai.']);

            return $provisioner->provision(
                ['code' => $data['organization_code'], 'name' => $data['organization_name']],
                $user,
            )['organization'];
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
        $user = User::query()->findOrFail($request->user()->getKey());

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
        $user = User::query()->findOrFail($request->user()->getKey());

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'max:200',
                Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

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

    private function freshTenantUser(Request $request): User
    {
        /** @var User $user */
        $user = User::query()->findOrFail($request->user()->getKey());
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
            $base['permissions'] = (new PlatformContext($user))->permissions();

            return $base;
        }

        $base['organizations'] = $this->access->accessible($user)
            ->map(fn (Organization $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name])
            ->values()->all();

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

        return $this->tenancy->runAsTenant(new TenantContext($organization, $user), function () use ($base, $user) {
            $context = $this->tenancy->tenant();
            $base['user']['job_title'] = $user->membership()->value('job_title');
            $base['permissions'] = $context->permissions();
            $base['data_scope'] = $context->scope()->toArray();

            return $base;
        });
    }
}
