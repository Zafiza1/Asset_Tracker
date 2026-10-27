<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-07: sessions, cache and queue tables move from the runtime role to their own roles.
 *
 * Session payloads are encrypted but not bound to their id: with SQL on the runtime connection
 * an attacker could copy another account's payload into their own session row and become that
 * account. The cache holds the login rate limits. Neither has row ownership that RLS could
 * check, so the runtime role loses access entirely:
 *
 *  - ate_session (connection pgsql_session): sessions, cache, cache_locks.
 *  - ate_queue (connection pgsql_queue): jobs, job_batches, failed_jobs. No job is queued
 *    today; the role keeps a future worker away from tenant data.
 *
 * Prerequisites (DBA, see README): both roles exist with LOGIN and no other attributes or
 * memberships.
 */
return new class extends Migration
{
    private const SESSION_TABLES = ['sessions', 'cache', 'cache_locks'];

    private const QUEUE_TABLES = ['jobs', 'job_batches', 'failed_jobs'];

    private const QUEUE_SEQUENCES = ['jobs_id_seq', 'failed_jobs_id_seq'];

    public function up(): void
    {
        $app = config('database.app_role');
        $session = config('database.connections.pgsql_session.username');
        $queue = config('database.connections.pgsql_queue.username');
        $this->assertRoles([$session, $queue], $app);

        $sessionTables = implode(', ', self::SESSION_TABLES);
        $queueTables = implode(', ', self::QUEUE_TABLES);
        $sequences = implode(', ', self::QUEUE_SEQUENCES);

        DB::statement("REVOKE ALL ON {$sessionTables}, {$queueTables} FROM {$app}");
        DB::statement("REVOKE ALL ON SEQUENCE {$sequences} FROM {$app}");

        DB::statement("GRANT USAGE ON SCHEMA public TO {$session}, {$queue}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$sessionTables} TO {$session}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$queueTables} TO {$queue}");
        DB::statement("GRANT USAGE, SELECT, UPDATE ON SEQUENCE {$sequences} TO {$queue}");
    }

    public function down(): void
    {
        $app = config('database.app_role');
        $session = config('database.connections.pgsql_session.username');
        $queue = config('database.connections.pgsql_queue.username');

        $sessionTables = implode(', ', self::SESSION_TABLES);
        $queueTables = implode(', ', self::QUEUE_TABLES);
        $sequences = implode(', ', self::QUEUE_SEQUENCES);

        DB::statement("REVOKE ALL ON {$sessionTables} FROM {$session}");
        DB::statement("REVOKE ALL ON {$queueTables} FROM {$queue}");
        DB::statement("REVOKE ALL ON SEQUENCE {$sequences} FROM {$queue}");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM {$session}, {$queue}");

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$sessionTables}, {$queueTables} TO {$app}");
        DB::statement("GRANT USAGE, SELECT, UPDATE ON SEQUENCE {$sequences} TO {$app}");
    }

    /** @param list<string> $roles */
    private function assertRoles(array $roles, string $app): void
    {
        $problems = [];
        foreach ($roles as $name) {
            $role = DB::selectOne(<<<'SQL'
                SELECT r.rolcanlogin, r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication AS elevated,
                       EXISTS (SELECT 1 FROM pg_auth_members m WHERE m.member = r.oid) AS member_of_any
                FROM pg_roles r WHERE r.rolname = ?
            SQL, [$name]);
            $problems = [...$problems, ...match (true) {
                $role === null => ["role {$name} does not exist"],
                $name === $app => ["{$name} must not be the runtime role"],
                default => array_keys(array_filter([
                    "{$name} needs LOGIN" => ! $role->rolcanlogin,
                    "{$name} must have no elevated attributes" => $role->elevated,
                    "{$name} must not be a member of other roles" => $role->member_of_any,
                ])),
            }];
        }
        if ($problems !== []) {
            throw new RuntimeException('Session/queue role prerequisites missing: '.implode('; ', $problems).'. See README (Database).');
        }
    }
};
