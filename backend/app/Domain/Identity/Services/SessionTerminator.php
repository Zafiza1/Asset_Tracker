<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revokes server-side sessions (database session driver). Tenant middleware also re-checks
 * user status on every request, so this is defense in depth for immediate revocation.
 */
final class SessionTerminator
{
    public function terminateAll(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
