<?php

namespace App\Domain\AssetTransactions;

/**
 * Nature and reason of an outgoing transaction (APR-0006 D-8). Temporary: the asset is expected
 * back (status off_site, planned return date required). Permanent: it never comes back (disposed).
 */
final class OutgoingReason
{
    public const TEMPORARY = 'temporary';

    public const PERMANENT = 'permanent';

    public const NATURES = [self::TEMPORARY, self::PERMANENT];

    public const NATURE_LABELS = [
        self::TEMPORARY => 'Sementara',
        self::PERMANENT => 'Permanen',
    ];

    /** "Other" needs an explanation in notes. */
    public const OTHER = 'other';

    /** @var array<string, array<string, string>> nature => reason code => label */
    public const REASONS = [
        self::TEMPORARY => [
            'lent_external' => 'Dipinjamkan ke pihak luar',
            'external_repair' => 'Perbaikan di vendor',
            'sent_to_project' => 'Dikirim ke proyek/klien',
            self::OTHER => 'Lainnya',
        ],
        self::PERMANENT => [
            'sold' => 'Dijual',
            'donated' => 'Dihibahkan',
            'returned_to_owner' => 'Dikembalikan ke pemilik/vendor',
            self::OTHER => 'Lainnya',
        ],
    ];

    /** @return list<string> every reason code, either nature */
    public static function codes(): array
    {
        return array_values(array_unique(array_merge(...array_map('array_keys', array_values(self::REASONS)))));
    }

    public static function allows(string $nature, string $reason): bool
    {
        return isset(self::REASONS[$nature][$reason]);
    }

    public static function label(?string $nature, ?string $reason): ?string
    {
        return $nature !== null && $reason !== null ? (self::REASONS[$nature][$reason] ?? null) : null;
    }
}
