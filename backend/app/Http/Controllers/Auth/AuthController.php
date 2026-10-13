<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionTerminator;
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

class AuthController extends Controller
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
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

        if ($user === null || ! $passwordOk || ! $user->isActive()) {
            $this->audit->record('auth.login_failed', $user, metadata: [
                'email' => $credentials['email'],
                'reason' => $user === null ? 'unknown_email' : (! $passwordOk ? 'bad_password' : 'inactive_account'),
            ], organizationId: $user?->organization_id);

            throw ApiException::unprocessable('INVALID_CREDENTIALS', 'Email atau password salah.');
        }

        if ($user->isTenantUser() && ! $user->organization->isActive()) {
            $this->audit->record('auth.login_failed', $user, metadata: ['reason' => 'organization_inactive'], organizationId: $user->organization_id, actor: $user);
            throw ApiException::forbidden('ORGANIZATION_INACTIVE', 'Organisasi Anda sedang tidak aktif.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login', $user, organizationId: $user->organization_id, actor: $user);

        return response()->json(['data' => $this->profile($user)]);
    }

    /**
     * Self-service sign-up: creates a new organization with the registrant as its
     * Organization Administrator (same provisioning as the platform console), then logs in.
     * Runs as system so the uniqueness checks and provisioning see every organization.
     */
    public function register(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        if (! config('auth.self_registration')) {
            throw ApiException::forbidden('REGISTRATION_DISABLED', 'Pendaftaran mandiri tidak tersedia. Hubungi administrator.');
        }

        $request->merge(['organization_code' => strtoupper((string) $request->input('organization_code'))]);
        $user = $this->tenancy->runAsSystem(function () use ($request, $provisioner) {
            $data = $request->validate([
                'organization_name' => ['required', 'string', 'max:150'],
                'organization_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,29}$/', Rule::unique('organizations', 'code')],
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
                'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
            ], [
                'organization_code.unique' => 'Kode organisasi sudah dipakai.',
                'email.unique' => 'Email sudah terdaftar. Silakan masuk.',
            ]);

            return $provisioner->provision(
                ['code' => $data['organization_code'], 'name' => $data['organization_name']],
                ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
                adminMustChangePassword: false,
            )['admin'];
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.registered', $user, organizationId: $user->organization_id, actor: $user);

        return response()->json(['data' => $this->profile($user)], 201);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->audit->record('auth.logout', $user, organizationId: $user->organization_id, actor: $user);

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

        return response()->json(['data' => $this->profile($user)]);
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
        $this->audit->record('auth.password_changed', $user, organizationId: $user->organization_id, actor: $user);

        return response()->json(['data' => $this->profile($user)]);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $base = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'job_title' => $user->job_title,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'organization' => null,
            'permissions' => [],
            'data_scope' => null,
        ];

        if ($user->isPlatformUser()) {
            $base['permissions'] = (new PlatformContext($user))->permissions();

            return $base;
        }

        $organization = $user->organization;
        $base['organization'] = (new OrganizationResource($organization))->resolve();

        return $this->tenancy->runAsTenant(new TenantContext($organization, $user), function () use ($base) {
            $context = $this->tenancy->tenant();
            $base['permissions'] = $context->permissions();
            $base['data_scope'] = $context->scope()->toArray();

            return $base;
        });
    }
}
