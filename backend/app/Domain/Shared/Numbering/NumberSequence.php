<?php

namespace App\Domain\Shared\Numbering;

use App\Domain\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Human-readable document numbers (asset numbers, transaction numbers) from an organization
 * format such as "AST-{YYYY}-{SEQ:6}". Tokens: {YYYY} {YY} {MM} {TYPE} {SEQ} / {SEQ:n}.
 *
 * The counter is incremented with a single upsert, which row-locks the sequence until the
 * caller's database transaction ends: concurrent callers get distinct values, and a rolled
 * back caller releases its value (no gaps from failed requests). Counters restart per year
 * (or month) when the format contains a year (or month) token.
 */
final class NumberSequence
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function next(string $sequenceKey, string $format, string $type = ''): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Number sequences must be used inside a database transaction.');
        }

        $organization = $this->tenancy->tenant()->organization;
        $now = CarbonImmutable::now($organization->timezone ?: 'UTC');
        $period = match (true) {
            str_contains($format, '{MM}') => $now->format('Ym'),
            str_contains($format, '{YYYY}'), str_contains($format, '{YY}') => $now->format('Y'),
            default => '',
        };

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO number_sequences (organization_id, sequence_key, period_key, next_value, created_at, updated_at)
            VALUES (?, ?, ?, 2, now(), now())
            ON CONFLICT (organization_id, sequence_key, period_key)
            DO UPDATE SET next_value = number_sequences.next_value + 1, updated_at = now()
            RETURNING next_value - 1 AS value
        SQL, [$organization->id, $sequenceKey, $period]);

        return self::format($format, (int) $row->value, $now, $type);
    }

    public static function format(string $format, int $value, CarbonImmutable $date, string $type = ''): string
    {
        $number = strtr($format, [
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{TYPE}' => $type,
        ]);

        return (string) preg_replace_callback(
            '/\{SEQ(?::(\d))?\}/',
            fn (array $m) => str_pad((string) $value, (int) ($m[1] ?? 1), '0', STR_PAD_LEFT),
            $number,
        );
    }
}
