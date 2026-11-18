<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One recorded activity on an asset. Append-only (DB trigger): never edited or deleted.
 * from_* / to_* pairs record exactly what the transaction changed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $transaction_number
 * @property string $asset_id
 * @property string $type
 * @property Carbon $transaction_date
 * @property string|null $from_location_id
 * @property string|null $to_location_id
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $notes
 * @property string|null $outgoing_nature temporary|permanent (outgoing only)
 * @property string|null $reason_code see OutgoingReason (outgoing only)
 * @property string|null $counterparty_name outside party (outgoing; incoming: received from)
 * @property string|null $counterparty_org
 * @property string|null $counterparty_contact personal data (APR-0006 D-10)
 * @property string|null $document_number delivery note / handover record / invoice
 * @property string|null $outgoing_transaction_id incoming only: the outgoing row it closes
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class AssetTransaction extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'due_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** Values of an organization type's fields: a JSON object even with no fields (column check). */
    protected function fieldValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : (json_decode($value, true) ?: []),
            set: fn (?array $value) => $value === null ? null : json_encode((object) $value),
        );
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The organization's transaction type of a 'custom' row (F-07). */
    public function typeDefinition(): BelongsTo
    {
        return $this->belongsTo(TransactionTypeDefinition::class, 'type_definition_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Incoming row: the outgoing row of the same asset it closes. */
    public function outgoingTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'outgoing_transaction_id');
    }

    /** Outgoing row: the incoming row that brought the asset back (none while still outside). */
    public function incomingTransaction(): HasOne
    {
        return $this->hasOne(self::class, 'outgoing_transaction_id');
    }

    /** Label of the outgoing reason (report field transaction.reason). */
    protected function reasonLabel(): Attribute
    {
        return Attribute::get(fn () => OutgoingReason::label($this->outgoing_nature, $this->reason_code));
    }

    /**
     * Temporary outgoing rows only: Sudah kembali / Terlambat / Belum kembali (report field
     * transaction.return_status). Needs incomingTransaction (eager-loaded by the report).
     */
    protected function returnStatus(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->type !== TransactionType::OUTGOING || $this->outgoing_nature !== OutgoingReason::TEMPORARY) {
                return null;
            }
            if ($this->incomingTransaction !== null) {
                return 'Sudah kembali';
            }

            return $this->due_date !== null && $this->due_date->toDateString() < self::today() ? 'Terlambat' : 'Belum kembali';
        });
    }

    /** Temporary outgoing rows only: days outside, until the return or until today. */
    protected function daysOut(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->type !== TransactionType::OUTGOING || $this->outgoing_nature !== OutgoingReason::TEMPORARY) {
                return null;
            }
            $until = $this->incomingTransaction?->transaction_date?->toDateString() ?? self::today();

            return (int) $this->transaction_date->startOfDay()->diffInDays(Carbon::parse($until)->startOfDay());
        });
    }

    private static function today(): string
    {
        return app(TransactionLog::class)->today();
    }
}
