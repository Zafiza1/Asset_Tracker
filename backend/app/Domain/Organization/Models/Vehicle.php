<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
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

    public const TYPES = [
        'truk' => 'Truk',
        'pickup' => 'Pick Up',
        'mobil' => 'Mobil',
        'motor' => 'Motor',
        'bus' => 'Bus',
        'forklift' => 'Forklift',
        'alat_berat' => 'Alat Berat',
        'lainnya' => 'Lainnya',
    ];

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
