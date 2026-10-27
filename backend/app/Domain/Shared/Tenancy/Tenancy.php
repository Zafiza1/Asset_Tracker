<?php

namespace App\Domain\Shared\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use WeakMap;

/**
 * Holds the execution context of the current request/job and mirrors it into the
 * PostgreSQL session used by Row-Level Security policies.
 *
 * Modes:
 *  - none:     no context. Tenant-owned models refuse to query (fail-closed) and RLS hides all rows.
 *  - tenant:   exactly one organization; queries are filtered to it.
 *  - platform: authenticated platform user; cross-tenant access allowed by explicit platform permissions.
 *  - system:   internal code paths (seeders, console, auth bootstrap). Never derived from request input.
 *
 * The runtime role gets the context as a signed claim (`app.context`, see ContextClaim and
 * migration 001300) bound to its backend, so SQL running on the connection cannot forge or
 * widen it (SEC-07). The owner role, which owns the tables anyway, gets the plain settings.
 *
 * Session settings are transactional, and connections can be replaced, so the context is
 * re-applied after every rollback, on every new backend, and before the claim expires.
 */
final class Tenancy
{
    private ?TenantContext $tenant = null;

    private ?PlatformContext $platform = null;

    private bool $system = false;

    private ?ContextClaim $claims = null;

    /** @var WeakMap<PDO, array{pid: int, started: int, owner: bool, signed_at: ?int}> */
    private WeakMap $backends;

    /** @var WeakMap<Connection, true> */
    private WeakMap $hooked;

    private bool $syncing = false;

    public function __construct()
    {
        $this->backends = new WeakMap;
        $this->hooked = new WeakMap;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): TenantContext
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function organizationId(): string
    {
        return $this->tenant()->organizationId();
    }

    public function platform(): ?PlatformContext
    {
        return $this->platform;
    }

    /** True when no tenant filter should be applied (platform or system mode). */
    public function isUnrestricted(): bool
    {
        return $this->tenant === null && ($this->system || $this->platform !== null);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsTenant(TenantContext $context, Closure $callback): mixed
    {
        return $this->run(new TenancyState($context, null, false), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsPlatform(PlatformContext $context, Closure $callback): mixed
    {
        return $this->run(new TenancyState(null, $context, false), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        return $this->run(new TenancyState(null, $this->platform, true), $callback);
    }

    /** Switch state and return the previous one (for callers that cannot use a closure). */
    public function enter(TenancyState $state): TenancyState
    {
        $previous = new TenancyState($this->tenant, $this->platform, $this->system);
        $this->apply($state);

        return $previous;
    }

    public function restore(TenancyState $state): void
    {
        $this->apply($state);
    }

    public function reset(): void
    {
        $this->apply(new TenancyState(null, null, false));
    }

    /** A connection was opened or re-opened (ConnectionEstablished). */
    public function connected(Connection $connection): void
    {
        $this->hook($connection);
        $this->resync($connection, force: false);
    }

    /** A transaction or savepoint was rolled back, which also rolled back the session settings. */
    public function rolledBack(Connection $connection): void
    {
        $this->resync($connection, force: true);
    }

    private function run(TenancyState $state, Closure $callback): mixed
    {
        $previous = $this->enter($state);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    private function apply(TenancyState $state): void
    {
        $this->tenant = $state->tenant;
        $this->platform = $state->platform;
        $this->system = $state->system;

        $connection = DB::connection();
        $this->hook($connection);
        $this->sync($connection);
    }

    /** Re-checks the context before each query: new backend, or a claim past half its lifetime. */
    private function hook(Connection $connection): void
    {
        if (! isset($this->hooked[$connection])) {
            $this->hooked[$connection] = true;
            $connection->beforeExecuting(fn ($query, $bindings, Connection $c) => $this->resync($c, force: false));
        }
    }

    private function resync(Connection $connection, bool $force): void
    {
        if ($this->syncing || $connection->getName() !== DB::getDefaultConnection()) {
            return;
        }
        $pdo = $connection->getRawPdo();
        if (! $pdo instanceof PDO && ! $pdo instanceof Closure) {
            return; // Disconnected: the reconnect fires ConnectionEstablished.
        }
        $backend = $pdo instanceof PDO ? ($this->backends[$pdo] ?? null) : null;
        if ($backend === null) {
            // A backend we never touched starts without context; it only needs one if we have one.
            if ($this->mode() !== null) {
                $this->sync($connection);
            }

            return;
        }
        $stale = $backend['signed_at'] === null
            || ($this->mode() !== null && ! $backend['owner'] && now()->getTimestamp() - $backend['signed_at'] >= intdiv($this->claims()->ttl, 2));
        if ($force || $stale) {
            $this->sync($connection);
        }
    }

    /**
     * Writes the current state into the session of the connection's backend. Runs on the raw
     * PDO: no query events or query log, and no exception that carries the claim as a binding.
     */
    private function sync(Connection $connection): void
    {
        if ($this->syncing) {
            return;
        }
        $this->syncing = true;
        $pdo = null;
        try {
            $pdo = $connection->getPdo();
            $backend = $this->backends[$pdo] ??= $this->describe($pdo);
            [$mode, $organizationId, $userId] = $this->mode() ?? [null, null, null];

            if ($backend['owner']) {
                $statement = $pdo->prepare("SELECT pg_catalog.set_config('app.organization_id', ?, false), pg_catalog.set_config('app.platform_context', ?, false)");
                $statement->execute([$organizationId ?? '', in_array($mode, ['platform', 'system'], true) ? 'on' : 'off']);
            } else {
                $claim = $mode === null ? '' : $this->claims()->sign($mode, $organizationId, $userId, $backend['pid'], $backend['started'], now()->getTimestamp());
                $statement = $pdo->prepare("SELECT pg_catalog.set_config('app.context', ?, false)");
                $statement->execute([$claim]);
            }
            $this->backends[$pdo] = ['signed_at' => now()->getTimestamp()] + $backend;
        } catch (PDOException $e) {
            if ($pdo instanceof PDO && isset($this->backends[$pdo])) {
                $this->backends[$pdo] = ['signed_at' => null] + $this->backends[$pdo];
            }
            // Inside an aborted transaction nothing can run; the rollback that must follow re-syncs.
            // Throwing here would also replace the exception that aborted the transaction.
            if ($e->getCode() !== '25P02') {
                // No previous exception: driver messages may quote the claim.
                throw new RuntimeException('Could not set the database context (SQLSTATE '.$e->getCode().').');
            }
        } finally {
            $this->syncing = false;
        }
    }

    /** @return array{pid: int, started: int, owner: bool, signed_at: null} */
    private function describe(PDO $pdo): array
    {
        $statement = $pdo->prepare(<<<'SQL'
            SELECT pg_catalog.pg_backend_pid() AS pid,
                   (EXTRACT(epoch FROM a.backend_start) * 1000000)::bigint AS started,
                   (session_user = ?)::int AS owner
            FROM pg_catalog.pg_stat_get_activity(pg_catalog.pg_backend_pid()) a
        SQL);
        $statement->execute([(string) config('database.connections.pgsql_owner.username')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['started'] === null) {
            throw new RuntimeException('The database backend start time is not readable.');
        }

        return ['pid' => (int) $row['pid'], 'started' => (int) $row['started'], 'owner' => (int) $row['owner'] === 1, 'signed_at' => null];
    }

    /** @return array{string, ?string, ?string}|null mode, organization id, user id; null without context */
    private function mode(): ?array
    {
        return match (true) {
            $this->tenant !== null => ['tenant', $this->tenant->organizationId(), $this->tenant->userId()],
            $this->system => ['system', null, null],
            $this->platform !== null => ['platform', null, $this->platform->user->id],
            default => null,
        };
    }

    private function claims(): ContextClaim
    {
        return $this->claims ??= ContextClaim::fromConfig();
    }
}
