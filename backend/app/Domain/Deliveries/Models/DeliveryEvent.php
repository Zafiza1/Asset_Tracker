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
 * Something that happened on a delivery, with where the phone was (append-only, DB trigger).
 *
 * @property string $id
 * @property string $delivery_id
 * @property string|null $stop_id
 * @property string|null $asset_id
 * @property string $type
 * @property string|null $note
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $accuracy metres
 * @property string|null $client_id
 * @property string $actor_id
 * @property Carbon $recorded_at when it happened on the phone
 */
class DeliveryEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    public const TYPES = [
        'created' => 'Transaksi dibuat',
        'updated' => 'Rencana diubah',
        'crew' => 'Kendaraan & kru diisi',
        'load' => 'Scan muat',
        'unscan' => 'Batal muat',
        'depart' => 'Berangkat',
        'position' => 'Lapor posisi',
        'issue' => 'Kendala di jalan',
        'resume' => 'Lanjut perjalanan',
        'arrive' => 'Tiba di customer',
        'unload' => 'Scan bongkar',
        'stop_done' => 'Selesai di customer',
        'return' => 'Tiba di gudang',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    /** What the phone reports (DeliveryService::report). */
    public const REPORTS = ['depart', 'position', 'issue', 'resume', 'arrive', 'stop_done', 'return', 'completed'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'stop_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
