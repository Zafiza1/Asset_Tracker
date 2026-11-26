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

    /** Asset leaves the organization for an outside party (see OutgoingReason). */
    public const OUTGOING = 'outgoing';

    /** A temporarily outgoing asset comes back; linked to its outgoing row. */
    public const INCOMING = 'incoming';

    /** A transaction of a type the organization defined itself (F-07); see TransactionTypeDefinition. */
    public const CUSTOM = 'custom';

    public const ALL = [self::REGISTRATION, self::TRANSFER, self::STATUS_CHANGE, self::LOAN, self::RETURN, self::NOTE, self::ARCHIVE, self::RESTORE, self::OUTGOING, self::INCOMING, self::CUSTOM];

    /** Types a user records in the Transactions menu; the others are written by asset actions. */
    public const MANUAL = [self::TRANSFER, self::STATUS_CHANGE, self::LOAN, self::RETURN, self::NOTE, self::OUTGOING, self::INCOMING];

    public const LABELS = [
        self::REGISTRATION => 'Penambahan aset',
        self::TRANSFER => 'Pemindahan lokasi',
        self::STATUS_CHANGE => 'Perubahan status',
        self::LOAN => 'Peminjaman',
        self::RETURN => 'Pengembalian',
        self::NOTE => 'Aktivitas lain',
        self::ARCHIVE => 'Penonaktifan',
        self::RESTORE => 'Pengaktifan kembali',
        self::OUTGOING => 'Transaksi keluar',
        self::INCOMING => 'Transaksi masuk',
        self::CUSTOM => 'Jenis business unit',
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
        self::OUTGOING => 'OUT',
        self::INCOMING => 'IN',
    ];
}
