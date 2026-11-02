<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\MobileAccessToken;
use App\Domain\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account routes of the web app (logout, password, organizations, invitations) work on the
 * session. The mobile scanner app's read-only token must not reach them: some would act
 * before failing on the missing session.
 */
final class RejectMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->currentAccessToken() instanceof MobileAccessToken) {
            throw ApiException::forbidden('SESSION_REQUIRED', 'Gunakan aplikasi web untuk tindakan ini.');
        }

        return $next($request);
    }
}
