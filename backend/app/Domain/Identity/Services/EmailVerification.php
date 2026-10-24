<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Proves that the owner of an account controls its email address.
 *
 * The email links to the SPA page /verifikasi-email with a temporary signed query
 * (id, hash of the email, expiry, signature) of the API route verification.verify; the page
 * sends that query to the API while logged in. The signature is relative (path + query), so
 * it holds behind the dev proxy and in production alike. A link only verifies the account it
 * was made for, for the address it was made for, and only for the logged-in owner.
 */
final class EmailVerification
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function link(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        return rtrim((string) config('app.frontend_url'), '/').'/verifikasi-email?'.parse_url($signed, PHP_URL_QUERY);
    }

    public function verify(User $user, Request $request, ?string $organizationId): void
    {
        if (! URL::hasCorrectSignature($request, absolute: false)) {
            throw ApiException::forbidden('VERIFICATION_LINK_INVALID', 'Tautan verifikasi tidak valid. Minta tautan baru.');
        }
        if (! URL::signatureHasNotExpired($request)) {
            throw new ApiException('VERIFICATION_LINK_EXPIRED', 'Tautan verifikasi sudah kedaluwarsa. Minta tautan baru.', 410);
        }
        // The link must belong to this account and to its current address (an email change voids it).
        if (! hash_equals((string) $user->getKey(), (string) $request->query('id'))
            || ! hash_equals(sha1($user->getEmailForVerification()), (string) $request->query('hash'))) {
            throw ApiException::forbidden('VERIFICATION_ACCOUNT_MISMATCH', 'Tautan verifikasi ini bukan untuk akun yang sedang masuk.');
        }
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->markEmailAsVerified();
        $this->audit->record('auth.email_verified', $user, metadata: ['method' => 'link'], organizationId: $organizationId, actor: $user);
    }
}
