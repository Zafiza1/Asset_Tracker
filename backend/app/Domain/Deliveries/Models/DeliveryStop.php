<?php

namespace App\Domain\Deliveries\Models;

use App\Domain\Organization\Models\Customer;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer on a delivery's route.
 *
 * @property string $id
 * @property string $delivery_id
 * @property int $sequence
 * @property string $customer_id
 * @property string $status
 * @property Carbon|null $arrived_at
 * @property Carbon|null $done_at
 * @property string|null $receiver_name
 * @property string|null $note
 */
class DeliveryStop extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const ARRIVED = 'arrived';

    public const DONE = 'done';

    /** Outgoing: nothing was loaded for this customer. */
    public const SKIPPED = 'skipped';

    public const STATUSES = [
        self::PENDING => 'Belum dikunjungi',
        self::ARRIVED => 'Sudah tiba',
        self::DONE => 'Selesai',
        self::SKIPPED => 'Dilewati',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'arrived_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::DONE, self::SKIPPED], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class, 'stop_id');
    }
}
