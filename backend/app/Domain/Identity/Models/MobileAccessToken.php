<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bearer token of the mobile scanner app, bound to the organization chosen at login.
 *
 * Tokens are only reachable in the system context (migration 2026_11_02_000100), and the
 * Sanctum guard reads, touches and resolves them before any tenant context exists, so the
 * lookup (with its account) and every write run in the system context, and only those.
 *
 * @property string $organization_id
 */
class MobileAccessToken extends PersonalAccessToken
{
    use HasUuids;

    /** Days a token works after login; the app then asks to log in again. */
    public const LIFETIME_DAYS = 30;

    protected $table = 'mobile_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'organization_id'];

    public static function findToken($token)
    {
        [$id] = explode('|', (string) $token, 2) + [1 => null];
        if (! Str::isUuid($id)) {
            return null;
        }

        return app(Tenancy::class)->runAsSystem(fn () => parent::findToken($token)?->loadMissing('tokenable'));
    }

    public function save(array $options = [])
    {
        return app(Tenancy::class)->runAsSystem(fn () => parent::save($options));
    }

    public function delete()
    {
        return app(Tenancy::class)->runAsSystem(fn () => parent::delete());
    }
}
