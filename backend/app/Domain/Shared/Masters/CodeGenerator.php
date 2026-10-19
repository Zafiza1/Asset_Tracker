<?php

namespace App\Domain\Shared\Masters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Derives a record code from its name when the user leaves the code empty:
 * "Gudang Utama" → GUDANG-UTAMA, then GUDANG-UTAMA-2, -3, … while taken in the organization.
 * Archived rows count as taken because codes are unique regardless of status.
 */
final class CodeGenerator
{
    public static function fromName(string $name, string $table, string $organizationId, int $max = 30, string $separator = '-', string $fallback = 'KODE'): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', $separator, strtoupper(Str::ascii($name))), $separator);
        // Role codes must start with a letter; other codes only need a letter or digit.
        if ($base === '' || ($separator === '_' && ! ctype_alpha($base[0]))) {
            $base = trim($fallback.$separator.$base, $separator);
        }

        $taken = DB::table($table)->where('organization_id', $organizationId)->pluck('code')->flip();
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '' : $separator.$n;
            $candidate = rtrim(substr($base, 0, $max - strlen($suffix)), $separator).$suffix;
            if (! $taken->has($candidate)) {
                return $candidate;
            }
        }
    }
}
