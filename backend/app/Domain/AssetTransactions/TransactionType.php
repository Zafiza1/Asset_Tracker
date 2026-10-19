<?php

namespace App\Domain\AssetTransactions;

/**
 * Kinds of asset transactions. Behaviour lives in AssetTransactionService; this class is
 * the single list of codes, labels, and number prefixes.
 */
final class TransactionType
{
    public const REGISTRATION = 'registration';

    public const TRANSFER = 'transfer';

    public const STATUS_CHANGE = 'status_change';

    public const LOAN = 'loan';

    public const RETURN = 'return';

    public const NOTE = 'note';

    public const ARCHIVE = 'archive';

    public const RESTORE = 'restore';

    public const ALL = [self::REGISTRATION, self::TRANSFER, self::STATUS_CHANGE, self::LOAN, self::RETURN, self::NOTE, self::ARCHIVE, self::RESTORE];

    /** Types a user records in the Transactions menu; the others are written by asset actions. */
    public const MANUAL = [self::TRANSFER, self::STATUS_CHANGE, self::LOAN, self::RETURN, self::NOTE];

    public const LABELS = [
        self::REGISTRATION => 'Penambahan aset',
        self::TRANSFER => 'Pemindahan lokasi',
        self::STATUS_CHANGE => 'Perubahan status',
        self::LOAN => 'Peminjaman',
        self::RETURN => 'Pengembalian',
        self::NOTE => 'Aktivitas lain',
        self::ARCHIVE => 'Penonaktifan',
        self::RESTORE => 'Pengaktifan kembali',
    ];

    /** {TYPE} token of the transaction number format. */
    public const PREFIXES = [
        self::REGISTRATION => 'REG',
        self::TRANSFER => 'TRF',
        self::STATUS_CHANGE => 'STS',
        self::LOAN => 'PJM',
        self::RETURN => 'KMB',
        self::NOTE => 'CAT',
        self::ARCHIVE => 'NON',
        self::RESTORE => 'AKT',
    ];
}
