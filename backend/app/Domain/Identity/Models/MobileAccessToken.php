<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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

    /**
     * A token works until the end of the day it was issued (organization's time zone), so a lost
     * phone is usable for one working day at most and every shift starts with a login (decision
     * of the product owner, 2026-11-17). A login with less than MIN_HOURS left in the day (e.g. a
     * night shift) runs to the end of the next day instead, so it never ends mid-shift.
     */
    public const MIN_HOURS = 6;

    public static function expiresAt(?string $timezone, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($timezone ?: 'UTC');
        $end = $local->endOfDay();
        if ($local->diffInHours($end) < self::MIN_HOURS) {
            $end = $local->addDay()->endOfDay();
        }

        return $end->utc();
    }

    /**
     * Ends the account's mobile logins: all of them (password changed or reset), or those of one
     * organization (membership deactivated). The app then asks to log in again.
     */
    public static function revokeFor(User $user, ?string $organizationId = null): int
    {
        return app(Tenancy::class)->runAsSystem(fn () => static::query()
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())
            ->when($organizationId, fn ($q, $id) => $q->where('organization_id', $id))
            ->delete());
    }

    protected $table = 'mobile_access_tokens';

    /**
     * The only writes the app may make ("METHOD uri" of the matched route): registering assets,
     * locations and categories, and following up approval requests. They keep their usual
     * permission and approval middleware, so a change by a user without approval.manage still
     * waits for approval. Every other write is rejected for the token (ResolveTenant).
     */
    public const WRITE_ROUTES = [
        'POST api/assets',
        'POST api/locations',
        'POST api/tenant-masters/categories',
        'POST api/approvals/{approvalRequest}/approve',
        'POST api/approvals/{approvalRequest}/reject',
        'POST api/approvals/{approvalRequest}/cancel',
    ];

    /**
     * Outgoing/incoming transactions from the scanner (F-03, APR-0006). Issued at login only to
     * accounts with transaction.create and asset.outgoing or asset.incoming; the route keeps its
     * permission checks, so revoking a permission takes effect on the next request.
     */
    public const TRANSACTION_WRITE = 'transaction:write';

    /** Deliveries (crew, scans, reports on the way): issued to accounts with delivery.operate. */
    public const DELIVERY_WRITE = 'delivery:write';

    /** Writes that need an ability on the token ("METHOD uri" => ability). */
    public const ABILITY_ROUTES = [
        'POST api/transactions' => self::TRANSACTION_WRITE,
        'PUT api/deliveries/{delivery}/crew' => self::DELIVERY_WRITE,
        'POST api/deliveries/{delivery}/scan' => self::DELIVERY_WRITE,
        'POST api/deliveries/{delivery}/unscan' => self::DELIVERY_WRITE,
        'POST api/deliveries/{delivery}/events' => self::DELIVERY_WRITE,
    ];

    /** Transaction types the app records; the others stay on the web. */
    public const TRANSACTION_TYPES = ['outgoing', 'incoming'];

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'organization_id'];

    /** Reads, the writes in WRITE_ROUTES, and the ABILITY_ROUTES this token holds the ability for. */
    public function allows(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return true;
        }
        $route = $request->route();
        if (! $route instanceof Route) {
            return false;
        }
        $key = $request->method().' '.$route->uri();
        if (in_array($key, self::WRITE_ROUTES, true)) {
            return true;
        }
        $ability = self::ABILITY_ROUTES[$key] ?? null;

        return $ability !== null && $this->can($ability)
            && ($ability !== self::TRANSACTION_WRITE || in_array($request->input('type'), self::TRANSACTION_TYPES, true));
    }

    /** Abilities a new token of this account gets in the organization (context of that organization). */
    public static function abilitiesFor(callable $can): array
    {
        $abilities = ['read'];
        if ($can('transaction.create') && ($can('asset.outgoing') || $can('asset.incoming'))) {
            $abilities[] = self::TRANSACTION_WRITE;
        }
        if ($can('delivery.operate')) {
            $abilities[] = self::DELIVERY_WRITE;
        }

        return $abilities;
    }

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
