<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-07: Row-Level Security trusts a signed claim, not plain session settings.
 *
 * Any role can set placeholder settings such as app.organization_id, so SQL injection on the
 * runtime connection could switch tenants or turn on the platform context. Now the policy
 * functions read `app.context`, a claim signed with HMAC-SHA256 (App\Domain\Shared\Tenancy\ContextClaim)
 * and verified by ate_ctx.verified_context():
 *
 *  - The verifier is SECURITY DEFINER, owned by the NOLOGIN role ate_ctx, with a fixed
 *    search_path and fully qualified names. Only ate_ctx can read the key (ate_private).
 *  - A claim is bound to one backend (pid + backend start), expires, and must match the
 *    database: tenant claims need an active membership of an active user in an active
 *    organization, platform claims an active platform account. An organization id alone
 *    is never enough.
 *  - A malformed, forged, expired or foreign claim raises 42501; no claim means no context.
 *  - Policies call the functions as `(SELECT f())`, so the verifier runs once per statement
 *    (InitPlan) and not once per row.
 *  - The owner role keeps its old settings (app.organization_id, app.platform_context = 'on',
 *    transaction-local in migrations; Tenancy sets them for seeders): it owns the tables and
 *    could disable RLS anyway. session_user cannot be changed by the runtime role.
 *
 * Prerequisites (superuser, see README): role ate_ctx NOLOGIN BYPASSRLS, member of
 * pg_read_all_stats, granted to the owner WITH INHERIT FALSE, SET TRUE (PostgreSQL 16+).
 * The key is installed separately: php artisan db:context-key install.
 */
return new class extends Migration
{
    private const POLICY_FUNCTIONS = ['ate_current_org', 'ate_platform_context'];

    public function up(): void
    {
        $owner = $this->currentRole();
        $ctx = config('database.context_role');
        $app = config('database.app_role');
        $this->assertPrerequisites($ctx);

        // Leftovers of an earlier run (migrate:fresh only drops tables in public).
        $this->asContextRole($ctx, fn () => DB::unprepared('DROP SCHEMA IF EXISTS ate_ctx CASCADE; DROP SCHEMA IF EXISTS ate_private CASCADE;'));

        DB::statement("CREATE SCHEMA ate_ctx AUTHORIZATION {$ctx}");
        DB::statement("CREATE SCHEMA ate_private AUTHORIZATION {$ctx}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$ctx}");
        // The verifier only needs these columns, and reads them without RLS (BYPASSRLS).
        DB::statement("GRANT SELECT (id, user_type, status) ON users TO {$ctx}");
        DB::statement("GRANT SELECT (organization_id, user_id, status) ON organization_members TO {$ctx}");
        DB::statement("GRANT SELECT (id, status) ON organizations TO {$ctx}");

        $this->asContextRole($ctx, function () use ($owner, $app) {
            DB::statement('REVOKE ALL ON SCHEMA ate_private FROM PUBLIC');
            DB::statement('REVOKE ALL ON SCHEMA ate_ctx FROM PUBLIC');
            DB::statement(<<<'SQL'
                CREATE TABLE ate_private.context_keys (
                    kid smallint PRIMARY KEY CHECK (kid BETWEEN 1 AND 32767),
                    inner_pad bytea NOT NULL CHECK (length(inner_pad) = 64),
                    outer_pad bytea NOT NULL CHECK (length(outer_pad) = 64),
                    active boolean NOT NULL DEFAULT true,
                    created_at timestamptz NOT NULL DEFAULT now(),
                    deactivated_at timestamptz
                )
            SQL);
            DB::statement('REVOKE ALL ON ate_private.context_keys FROM PUBLIC');
            DB::statement('CREATE TYPE ate_ctx.context AS (mode text, organization_id uuid, user_id uuid)');

            // Helpers are PL/pgSQL: SQL functions with SET cannot be inlined and are planned again
            // on every statement. Plain string functions: regular expressions with bounded repeats
            // cost ~1 ms per statement to compile. No SET (it costs per call): only ate_ctx can
            // execute them, from verified_context(), whose search_path is fixed.
            // Decimal without sign or leading zero, at most max_digits long.
            DB::statement(<<<'SQL'
                CREATE FUNCTION ate_ctx.is_decimal(value text, max_digits integer) RETURNS boolean
                LANGUAGE plpgsql IMMUTABLE STRICT
                AS $$ BEGIN
                    RETURN value <> '' AND pg_catalog.length(value) <= max_digits
                        AND pg_catalog.ltrim(value, '0123456789') = '' AND pg_catalog.left(value, 1) <> '0';
                END $$
            SQL);
            // Lowercase canonical UUID, or empty.
            DB::statement(<<<'SQL'
                CREATE FUNCTION ate_ctx.is_uuid_or_empty(value text) RETURNS boolean
                LANGUAGE plpgsql IMMUTABLE STRICT
                AS $$ BEGIN
                    RETURN value = '' OR (pg_catalog.length(value) = 36
                        AND pg_catalog.translate(value, '0123456789abcdef', '') = '----'
                        AND pg_catalog.substr(value, 9, 1) = '-' AND pg_catalog.substr(value, 14, 1) = '-'
                        AND pg_catalog.substr(value, 19, 1) = '-' AND pg_catalog.substr(value, 24, 1) = '-');
                END $$
            SQL);
            foreach (['is_decimal(text, integer)', 'is_uuid_or_empty(text)'] as $fn) {
                DB::statement("REVOKE ALL ON FUNCTION ate_ctx.{$fn} FROM PUBLIC");
            }

            DB::statement(<<<'SQL'
                CREATE FUNCTION ate_ctx.verified_context() RETURNS ate_ctx.context
                LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp
                AS $$
                DECLARE
                    raw text := pg_catalog.current_setting('app.context', true);
                    payload text;
                    mac text;
                    f text[];
                    k record;
                    given bytea;
                    expected bytea;
                    started bigint;
                    result ate_ctx.context;
                BEGIN
                    IF raw IS NULL OR raw = '' THEN
                        RETURN NULL;
                    END IF;
                    -- v1|kid|pid|backend_start_us|expires|mode|org|user.mac; see ContextClaim.
                    IF pg_catalog.length(raw) NOT BETWEEN 80 AND 256
                        OR pg_catalog.substr(raw, pg_catalog.length(raw) - 64, 1) <> '.' THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;
                    payload := pg_catalog.left(raw, -65);
                    mac := pg_catalog.right(raw, 64);
                    f := pg_catalog.string_to_array(payload, '|');
                    IF pg_catalog.cardinality(f) <> 8 OR f[1] <> 'v1' OR NOT ate_ctx.is_decimal(f[2], 5)
                        OR pg_catalog.ltrim(mac, '0123456789abcdef') <> '' THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;

                    SELECT ck.inner_pad, ck.outer_pad INTO k FROM ate_private.context_keys ck
                    WHERE ck.kid = f[2]::integer AND ck.active;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;
                    -- HMAC-SHA256 = sha256(outer_pad || sha256(inner_pad || message)).
                    given := pg_catalog.decode(mac, 'hex');
                    expected := pg_catalog.sha256(k.outer_pad || pg_catalog.sha256(k.inner_pad || pg_catalog.convert_to(payload, 'UTF8')));
                    -- Compare MACs of both values, so the comparison time says nothing about the expected MAC.
                    IF pg_catalog.sha256(k.outer_pad || pg_catalog.sha256(k.inner_pad || given))
                        <> pg_catalog.sha256(k.outer_pad || pg_catalog.sha256(k.inner_pad || expected)) THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;
                    -- Signed by us, so canonical; checked again so that no cast below can fail differently.
                    IF NOT (ate_ctx.is_decimal(f[3], 10) AND ate_ctx.is_decimal(f[4], 17) AND ate_ctx.is_decimal(f[5], 12)
                        AND f[6] IN ('tenant', 'platform', 'system') AND ate_ctx.is_uuid_or_empty(f[7]) AND ate_ctx.is_uuid_or_empty(f[8])) THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;

                    -- Bound to this backend. NULL (stats not readable) must fail, never skip the check.
                    SELECT (EXTRACT(epoch FROM a.backend_start) * 1000000)::bigint INTO started
                    FROM pg_catalog.pg_stat_get_activity(pg_catalog.pg_backend_pid()) a;
                    IF f[3]::bigint IS DISTINCT FROM pg_catalog.pg_backend_pid() OR started IS NULL
                        OR started IS DISTINCT FROM f[4]::bigint THEN
                        RAISE EXCEPTION 'database context belongs to another connection' USING ERRCODE = '42501';
                    END IF;
                    IF f[5]::bigint <= EXTRACT(epoch FROM pg_catalog.clock_timestamp())::bigint THEN
                        RAISE EXCEPTION 'database context expired' USING ERRCODE = '42501';
                    END IF;

                    result := ROW(f[6], nullif(f[7], '')::uuid, nullif(f[8], '')::uuid)::ate_ctx.context;
                    IF result.mode = 'tenant' THEN
                        PERFORM 1 FROM public.organization_members om
                            JOIN public.users u ON u.id = om.user_id
                            JOIN public.organizations o ON o.id = om.organization_id
                        WHERE om.organization_id = result.organization_id
                            AND om.user_id = result.user_id
                            AND om.status = 'active' AND u.status = 'active' AND u.user_type = 'tenant' AND o.status = 'active';
                        IF result.organization_id IS NULL OR result.user_id IS NULL OR NOT FOUND THEN
                            RAISE EXCEPTION 'database context is not authorized' USING ERRCODE = '42501';
                        END IF;
                    ELSIF result.mode = 'platform' THEN
                        PERFORM 1 FROM public.users u
                        WHERE u.id = result.user_id AND u.user_type = 'platform' AND u.status = 'active';
                        IF result.organization_id IS NOT NULL OR result.user_id IS NULL OR NOT FOUND THEN
                            RAISE EXCEPTION 'database context is not authorized' USING ERRCODE = '42501';
                        END IF;
                    ELSIF result.organization_id IS NOT NULL OR result.user_id IS NOT NULL THEN
                        RAISE EXCEPTION 'invalid database context' USING ERRCODE = '42501';
                    END IF;

                    RETURN result;
                END $$
            SQL);
            DB::statement('REVOKE ALL ON FUNCTION ate_ctx.verified_context() FROM PUBLIC');
            DB::statement("GRANT USAGE ON SCHEMA ate_ctx TO {$app}, {$owner}");
            DB::statement("GRANT EXECUTE ON FUNCTION ate_ctx.verified_context() TO {$app}, {$owner}");
        });

        // Invoker-rights wrappers used by policies and triggers (same OIDs: policies keep working).
        // PL/pgSQL, compiled once per session (see the helpers above).
        $ownerLiteral = DB::getPdo()->quote($owner);
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION public.ate_current_org() RETURNS uuid
            LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp
            AS \$\$ DECLARE c ate_ctx.context; BEGIN
                IF session_user = {$ownerLiteral} THEN
                    RETURN nullif(pg_catalog.current_setting('app.organization_id', true), '')::uuid;
                END IF;
                c := ate_ctx.verified_context();
                RETURN CASE WHEN c.mode = 'tenant' THEN c.organization_id END;
            END \$\$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_current_user() RETURNS uuid
            LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp
            AS $$ BEGIN RETURN (ate_ctx.verified_context()).user_id; END $$
        SQL);
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION public.ate_platform_context() RETURNS boolean
            LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp
            AS \$\$ BEGIN
                IF session_user = {$ownerLiteral} THEN
                    RETURN coalesce(pg_catalog.current_setting('app.platform_context', true) = 'on', false);
                END IF;
                RETURN coalesce((ate_ctx.verified_context()).mode IN ('platform', 'system'), false);
            END \$\$
        SQL);
        foreach (['ate_current_org()', 'ate_current_user()', 'ate_platform_context()'] as $fn) {
            DB::statement("REVOKE ALL ON FUNCTION public.{$fn} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$fn} TO {$app}");
        }

        $this->rewritePolicies(wrap: true);
    }

    public function down(): void
    {
        $ctx = config('database.context_role');
        $this->rewritePolicies(wrap: false);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_current_org() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT nullif(current_setting('app.organization_id', true), '')::uuid $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.ate_platform_context() RETURNS boolean
            LANGUAGE sql STABLE
            AS $$ SELECT coalesce(current_setting('app.platform_context', true), '') = 'on' $$
        SQL);
        DB::statement('DROP FUNCTION public.ate_current_user()');
        foreach (['ate_current_org()', 'ate_platform_context()'] as $fn) {
            DB::statement("GRANT EXECUTE ON FUNCTION public.{$fn} TO PUBLIC");
        }

        $this->asContextRole($ctx, fn () => DB::unprepared('DROP SCHEMA ate_ctx CASCADE; DROP SCHEMA ate_private CASCADE;'));
        DB::statement("REVOKE SELECT ON users, organization_members, organizations FROM {$ctx}");
        DB::statement("REVOKE USAGE ON SCHEMA public FROM {$ctx}");
    }

    /** `f()` <-> `(SELECT f())` in every policy expression of the public schema. */
    private function rewritePolicies(bool $wrap): void
    {
        $policies = DB::select("SELECT tablename, policyname, qual, with_check FROM pg_policies WHERE schemaname = 'public'");
        foreach ($policies as $policy) {
            $clauses = [];
            foreach (['USING' => $policy->qual, 'WITH CHECK' => $policy->with_check] as $clause => $expression) {
                if ($expression !== null) {
                    $clauses[] = "{$clause} (".$this->rewriteExpression($expression, $wrap).')';
                }
            }
            DB::statement(sprintf('ALTER POLICY %s ON %s %s', $policy->policyname, $policy->tablename, implode(' ', $clauses)));
        }
    }

    private function rewriteExpression(string $expression, bool $wrap): string
    {
        foreach (self::POLICY_FUNCTIONS as $fn) {
            $expression = $wrap
                ? preg_replace('/(?<!SELECT )\b'.$fn.'\(\)/', "(SELECT {$fn}())", $expression)
                : preg_replace('/\(\s*SELECT '.$fn.'\(\)(?: AS '.$fn.')?\)/', "{$fn}()", $expression);
        }

        return $expression;
    }

    private function assertPrerequisites(string $ctx): void
    {
        $role = DB::selectOne(<<<'SQL'
            SELECT r.rolcanlogin, r.rolbypassrls, r.rolsuper,
                   pg_has_role(r.oid, 'pg_read_all_stats', 'USAGE') AS stats,
                   pg_has_role(current_user, r.oid, 'SET') AS can_set,
                   current_setting('server_version_num')::int AS version
            FROM pg_roles r WHERE r.rolname = ?
        SQL, [$ctx]);

        $problems = match (true) {
            $role === null => ["role {$ctx} does not exist"],
            default => array_keys(array_filter([
                'PostgreSQL 16 or newer is required' => $role->version < 160000,
                "{$ctx} must be NOLOGIN" => $role->rolcanlogin,
                "{$ctx} must not be a superuser" => $role->rolsuper,
                "{$ctx} needs BYPASSRLS (it reads memberships for the verifier)" => ! $role->rolbypassrls,
                "{$ctx} must be a member of pg_read_all_stats" => ! $role->stats,
                "the migration role must be granted {$ctx} WITH SET TRUE" => ! $role->can_set,
            ])),
        };
        if ($problems !== []) {
            throw new RuntimeException('Signed database context prerequisites missing: '.implode('; ', $problems).'. See README (Database).');
        }
    }

    private function asContextRole(string $ctx, Closure $callback): void
    {
        // Transaction-local: if the callback fails, the rollback also ends the role switch.
        DB::statement("SET LOCAL ROLE {$ctx}");
        $callback();
        DB::statement('RESET ROLE');
    }

    private function currentRole(): string
    {
        return DB::selectOne('SELECT current_user AS name')->name;
    }
};
