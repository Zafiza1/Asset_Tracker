<?php

namespace App\Domain\Organization\Models;

use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A vehicle deliveries are made with (Master Data › Kendaraan). Not an asset: cylinders are the
 * assets, vehicles have their own fields.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string $plate_number
 * @property string $type
 * @property int|null $year
 * @property string|null $chassis_number
 * @property string|null $engine_number
 * @property string|null $fuel
 * @property string|null $capacity
 * @property Carbon|null $stnk_expires_on
 * @property Carbon|null $kir_expires_on
 * @property string $status
 */
class Vehicle extends Model
{
    use BelongsToOrganization, HasUuids;

    /** The kinds a vehicle can be given: cylinders are delivered by truck or pick up. */
    public const TYPES = [
        'truk' => 'Truk',
        'pickup' => 'Pick Up',
    ];

    /** Kinds of vehicles registered before; still named, no longer chosen. */
    private const OLD_TYPES = [
        'mobil' => 'Mobil',
        'motor' => 'Motor',
        'bus' => 'Bus',
        'forklift' => 'Forklift',
        'alat_berat' => 'Alat Berat',
        'lainnya' => 'Lainnya',
    ];

    /** Tersedia / Sedang Digunakan: set by the system from the transactions, never by hand. */
    public const AVAILABLE = 'available';

    public const IN_USE = 'in_use';

    public const USAGES = [
        self::AVAILABLE => 'Tersedia',
        self::IN_USE => 'Sedang Digunakan',
    ];

    public static function typeLabel(?string $type): ?string
    {
        return $type === null ? null : (self::TYPES[$type] ?? self::OLD_TYPES[$type] ?? $type);
    }

    /**
     * Adds `open_delivery_number`: the transaction not yet finished (Delivery::OPEN) the vehicle is
     * on, null while it is free.
     */
    public function scopeWithUsage(Builder $query): void
    {
        $query->select('vehicles.*')->addSelect(['open_delivery_number' => Delivery::query()->select('number')
            ->whereColumn('deliveries.vehicle_id', 'vehicles.id')->whereIn('deliveries.status', Delivery::OPEN)
            ->latest('deliveries.created_at')->limit(1)]);
    }

    /** Keeps the vehicles that are free (available) or on a transaction not yet finished (in_use). */
    public function scopeWhereUsage(Builder $query, string $usage): void
    {
        $open = fn ($q) => $q->selectRaw('1')->from('deliveries')->whereColumn('deliveries.vehicle_id', 'vehicles.id')->whereIn('deliveries.status', Delivery::OPEN);
        $usage === self::IN_USE ? $query->whereExists($open) : $query->whereNotExists($open);
    }

    /** The open transaction's number (from withUsage(), or looked up), null while free. */
    public function openDeliveryNumber(): ?string
    {
        if (array_key_exists('open_delivery_number', $this->attributes)) {
            return $this->attributes['open_delivery_number'];
        }

        return Delivery::query()->where('vehicle_id', $this->id)->whereIn('status', Delivery::OPEN)->latest()->value('number');
    }

    public const FUELS = [
        'bensin' => 'Bensin',
        'solar' => 'Solar',
        'listrik' => 'Listrik',
        'hybrid' => 'Hybrid',
        'gas' => 'Gas',
        'lainnya' => 'Lainnya',
    ];

    protected $fillable = ['code', 'name', 'plate_number', 'type', 'year', 'chassis_number', 'engine_number', 'fuel', 'capacity',
        'stnk_expires_on', 'kir_expires_on', 'status'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'capacity' => 'decimal:2', 'stnk_expires_on' => 'date', 'kir_expires_on' => 'date'];
    }
}
