<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
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
}
