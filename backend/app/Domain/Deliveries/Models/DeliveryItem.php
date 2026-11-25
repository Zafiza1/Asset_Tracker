<?php

namespace App\Domain\Deliveries\Models;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cylinder on a delivery, for one of its stops.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $stop_id
 * @property string $asset_id
 * @property bool $planned chosen beforehand (false: added by scanning)
 * @property string $state
 * @property bool $active holds the cylinder: no other transaction until this ends
 * @property Carbon|null $loaded_at
 * @property Carbon|null $unloaded_at
 */
class DeliveryItem extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PLANNED = 'planned';

    public const LOADED = 'loaded';

    public const UNLOADED = 'unloaded';

    /** Stayed behind: not scanned before leaving. */
    public const NOT_LOADED = 'not_loaded';

    /** Outgoing: not taken by the customer, back at the warehouse. */
    public const RETURNED = 'returned';

    /** Incoming: loaded at the customer but not unloaded at the warehouse. */
    public const MISSING = 'missing';

    public const CANCELLED = 'cancelled';

    public const STATES = [
        self::PLANNED => 'Belum dimuat',
        self::LOADED => 'Di kendaraan',
        self::UNLOADED => 'Sudah dibongkar',
        self::NOT_LOADED => 'Tidak dimuat',
        self::RETURNED => 'Kembali ke gudang',
        self::MISSING => 'Selisih',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected function casts(): array
    {
        return ['planned' => 'boolean', 'active' => 'boolean', 'loaded_at' => 'datetime', 'unloaded_at' => 'datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'stop_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function loader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by');
    }

    public function unloader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unloaded_by');
    }
}
