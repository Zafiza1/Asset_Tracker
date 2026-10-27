<?php

namespace App\Console\Commands;

use App\Domain\Shared\Tenancy\ContextClaim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Manages the keys of the signed database context (SEC-07) through the owner connection.
 *
 *   php artisan db:context-key install           installs DB_CONTEXT_KEY as DB_CONTEXT_KID (active)
 *   php artisan db:context-key status            lists key ids and their state (never the keys)
 *   php artisan db:context-key deactivate --kid=1
 *
 * Rotation: install the new key under a new kid, deploy DB_CONTEXT_KEY/DB_CONTEXT_KID, then
 * deactivate the old kid. Claims signed with a deactivated kid are rejected immediately.
 *
 * The key travels as a bind parameter. PostgreSQL logs bind parameters with statement logging
 * (log_statement = 'all', log_min_duration_statement, auto_explain), so keep those off while
 * installing. Errors are reported by SQLSTATE only, never with the statement or its values.
 */
class DatabaseContextKey extends Command
{
    protected $signature = 'db:context-key
        {action : install, status or deactivate}
        {--kid= : Key id for deactivate}
        {--database=pgsql_owner : Owner connection}';

    protected $description = 'Install, list or deactivate the keys of the signed database context';

    public function handle(): int
    {
        try {
            return match ($this->argument('action')) {
                'install' => $this->install(),
                'status' => $this->status(),
                'deactivate' => $this->deactivate(),
                default => $this->failWith('Unknown action. Use install, status or deactivate.'),
            };
        } catch (PDOException $e) {
            // No message: driver messages may quote the failing statement and its parameters.
            return $this->failWith('Database context key operation failed (SQLSTATE '.$e->getCode().').');
        }
    }

    private function install(): int
    {
        $claims = ContextClaim::fromConfig();
        $pads = ContextClaim::pads(ContextClaim::decodeKey(config('database.context.key')));

        $installed = $this->asContextRole(function (PDO $pdo) use ($claims, $pads) {
            $existing = $pdo->prepare('SELECT (inner_pad = :inner AND outer_pad = :outer)::int AS same FROM ate_private.context_keys WHERE kid = :kid');
            $this->bindKey($existing, $claims->kid(), $pads);
            $existing->execute();
            // A row whose key differs must not look like "no row" (fetchColumn() returns false for both).
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $insert = $pdo->prepare('INSERT INTO ate_private.context_keys (kid, inner_pad, outer_pad) VALUES (:kid, :inner, :outer)');
                $this->bindKey($insert, $claims->kid(), $pads);
                $insert->execute();

                return 'installed';
            }

            return (int) $row['same'] === 1 ? 'unchanged' : 'conflict';
        });

        if ($installed === 'conflict') {
            return $this->failWith("Key id {$claims->kid()} already holds a different key. Use a new DB_CONTEXT_KID for a new key.");
        }
        $this->info("Database context key {$claims->kid()}: {$installed}.");

        return self::SUCCESS;
    }

    private function status(): int
    {
        $rows = $this->asContextRole(fn (PDO $pdo) => $pdo
            ->query('SELECT kid, active, created_at, deactivated_at FROM ate_private.context_keys ORDER BY kid')
            ->fetchAll(PDO::FETCH_ASSOC));
        $this->table(['kid', 'active', 'created_at', 'deactivated_at'], $rows);

        return self::SUCCESS;
    }

    private function deactivate(): int
    {
        $kid = (int) $this->option('kid');
        if ($kid < 1) {
            return $this->failWith('--kid is required.');
        }
        $count = $this->asContextRole(function (PDO $pdo) use ($kid) {
            $statement = $pdo->prepare('UPDATE ate_private.context_keys SET active = false, deactivated_at = now() WHERE kid = :kid AND active');
            $statement->bindValue('kid', $kid, PDO::PARAM_INT);
            $statement->execute();

            return $statement->rowCount();
        });
        $this->info($count === 1 ? "Key {$kid} deactivated." : "Key {$kid} was not active.");

        return self::SUCCESS;
    }

    /**
     * Runs on the raw PDO of the owner connection (no query events, query log or exceptions that
     * carry bindings), as the context role, inside one transaction.
     */
    private function asContextRole(callable $callback): mixed
    {
        $connection = DB::connection((string) $this->option('database'));
        $pdo = $connection->getPdo();
        $role = $connection->getQueryGrammar()->wrap(config('database.context_role'));

        $pdo->beginTransaction();
        try {
            $pdo->exec("SET LOCAL ROLE {$role}");
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array{inner: string, outer: string} $pads */
    private function bindKey(PDOStatement $statement, int $kid, array $pads): void
    {
        $statement->bindValue('kid', $kid, PDO::PARAM_INT);
        $statement->bindValue('inner', $pads['inner'], PDO::PARAM_LOB);
        $statement->bindValue('outer', $pads['outer'], PDO::PARAM_LOB);
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
