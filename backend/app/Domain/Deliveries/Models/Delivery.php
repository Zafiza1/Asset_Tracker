<?php

namespace App\Domain\Deliveries\Models;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Vehicle;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One trip that brings cylinders to customers (outgoing) or back from them (incoming).
 * Every change goes through DeliveryService.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number also the number of the asset transactions it records
 * @property string $direction
 * @property string $status
 * @property string|null $vehicle_id
 * @property string|null $driver_id
 * @property string|null $helper_id
 * @property Carbon $planned_date
 * @property Carbon|null $expected_return_date outgoing: when the cylinders should be back
 * @property string|null $notes
 * @property string $created_by
 * @property Carbon|null $departed_at
 * @property Carbon|null $returned_at back at the warehouse
 * @property Carbon|null $ended_at
 * @property string|null $end_note
 */
class Delivery extends Model
{
    use BelongsToOrganization, HasUuids;

    public const OUTGOING = 'outgoing';

    public const INCOMING = 'incoming';

    public const DIRECTIONS = [self::OUTGOING => 'Transaksi Keluar', self::INCOMING => 'Transaksi Masuk'];

    public const DRAFT = 'draft';

    public const LOADING = 'loading';

    public const IN_TRANSIT = 'in_transit';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Disiapkan',
        self::LOADING => 'Sedang Muat',
        self::IN_TRANSIT => 'Dalam Perjalanan',
        self::COMPLETED => 'Selesai',
        self::CANCELLED => 'Dibatalkan',
    ];

    public const OPEN = [self::DRAFT, self::LOADING, self::IN_TRANSIT];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'expected_return_date' => 'date',
            'departed_at' => 'datetime',
            'returned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Members limited to some assets see the deliveries of those assets and their own. */
    public static function visible(Builder $query): Builder
    {
        $scope = app(AssetScope::class);
        if ($scope->isOrganizationWide()) {
            return $query;
        }

        return $query->where(fn ($w) => $w
            ->where('created_by', app(Tenancy::class)->tenant()->userId())
            ->orWhereHas('items', fn ($q) => $q->whereIn('asset_id', $scope->apply(Asset::query())->select('id'))));
    }

    /** Route binding also applies the user's data scope: other deliveries are 404. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = $query instanceof Model ? $query->newQuery() : $query;
        if (app(Tenancy::class)->hasTenant()) {
            self::visible($query);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    public function isOutgoing(): bool
    {
        return $this->direction === self::OUTGOING;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'helper_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(DeliveryStop::class)->orderBy('sequence');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class);
    }
}
