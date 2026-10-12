<?php

namespace App\Domain\AssetTransactions;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Numbering\NumberSequence;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * The only writer of asset_transactions. Callers have already applied (or are applying, in
 * the same database transaction) the change to the asset; this records it with a number.
 */
final class TransactionLog
{
    private const CHANGE_COLUMNS = ['from_location_id', 'to_location_id', 'from_status', 'to_status',
        'from_condition_id', 'to_condition_id', 'borrower_name', 'due_date'];

    public function __construct(
        private readonly NumberSequence $numbers,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  subset of CHANGE_COLUMNS
     */
    public function record(Asset $asset, string $type, array $changes = [], ?string $date = null, ?string $notes = null): AssetTransaction
    {
        $format = OrganizationSetting::query()->value('transaction_number_format') ?: '{TYPE}-{YYYY}-{SEQ:6}';

        $transaction = new AssetTransaction;
        $transaction->forceFill([
            ...array_intersect_key($changes, array_flip(self::CHANGE_COLUMNS)),
            'transaction_number' => $this->numbers->next('transaction', $format, TransactionType::PREFIXES[$type]),
            'asset_id' => $asset->id,
            'type' => $type,
            'transaction_date' => $date ?? $this->today(),
            'notes' => $notes,
            'created_by' => $this->tenancy->tenant()->userId(),
        ])->save();

        return $transaction;
    }

    /** Today in the organization's time zone. */
    public function today(): string
    {
        return now($this->tenancy->tenant()->organization->timezone ?: 'UTC')->toDateString();
    }
}
