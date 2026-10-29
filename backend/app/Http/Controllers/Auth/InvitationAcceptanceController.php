<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * The invitee's side of an invitation. The token always travels in the request body (never
 * in a URL, so it stays out of access logs). Only the holder of a valid token learns anything
 * about the invitation; answers for unknown tokens do not depend on any email address.
 * Accepting does not change the active organization; the client may switch to it afterwards.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly OrganizationInvitations $invitations,
        private readonly Tenancy $tenancy,
    ) {}

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        ['invitation' => $invitation, 'organization' => $organization, 'inviter' => $inviter, 'roles' => $roles] = $this->invitations->inspect($data['token']);

        return response()->json(['data' => [
            'email' => $invitation->email,
            'organization' => $this->summary($organization),
            'invited_by' => $inviter?->name ?? OrganizationInvitations::PLATFORM_INVITER_NAME,
            'roles' => $roles->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->values(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    /** The logged-in owner of the invited (verified) address accepts. */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        /** @var User $user */
        // No context on these routes; RLS protects accounts (SEC-07).
        $user = $this->tenancy->runAsSystem(fn () => User::query()->findOrFail($request->user()->getKey()));
        if (! $user->isActive()) {
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }
        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Anda harus mengganti password sebelum melanjutkan.');
        }

        ['organization' => $organization] = $this->invitations->accept($user, $data['token']);

        return response()->json(['data' => ['organization' => $this->summary($organization)]]);
    }

    /** An invited address without an account signs up and joins in one step, then is logged in. */
    public function register(Request $request, OrganizationAccess $access): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(12)->letters()->mixedCase()->numbers()],
            'license_code' => ['nullable', 'string', 'max:19'],
        ]);

        ['user' => $user, 'organization' => $organization] = $this->invitations->registerAndAccept(
            $data['token'],
            $data['name'],
            $data['password'],
            $data['license_code'] ?? null
        );

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $access->switch($request->session(), $user, $organization);
        }
        $this->tenancy->runAsSystem(fn () => $user->forceFill(['last_login_at' => now()])->save());

        return response()->json(['data' => ['organization' => $this->summary($organization)]], 201);
    }

    /** @return array{id: string, code: string, name: string} */
    private function summary(Organization $organization): array
    {
        return ['id' => $organization->id, 'code' => $organization->code, 'name' => $organization->name];
    }
}
