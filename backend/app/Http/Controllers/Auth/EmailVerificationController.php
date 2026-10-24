<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\EmailVerification;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Email verification of the logged-in account (see EmailVerification). */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly OrganizationAccess $access) {}

    /** Sends a new verification link to the account's address (rate limited). */
    public function send(Request $request): JsonResponse
    {
        $user = $this->freshUser($request);
        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['verified' => true]]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['verified' => false, 'sent' => true]], 202);
    }

    /** Confirms a signed link from the verification email; only its owner, logged in, can use it. */
    public function verify(Request $request, EmailVerification $verification): JsonResponse
    {
        $user = $this->freshUser($request);
        $organizationId = $user->isTenantUser() ? $this->access->current($request->session(), $user)?->id : null;
        $verification->verify($user, $request, $organizationId);

        return response()->json(['data' => ['verified' => true, 'email_verified_at' => $user->email_verified_at?->toIso8601String()]]);
    }

    private function freshUser(Request $request): User
    {
        return User::query()->findOrFail($request->user()->getKey());
    }
}
