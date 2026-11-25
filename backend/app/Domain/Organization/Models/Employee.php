<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person working for the organization (Master Data › Karyawan). Not an account: drivers and
 * helpers need no login to be on a delivery.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code NIK
 * @property string $name
 * @property string $position
 * @property string|null $phone
 * @property string|null $license_number SIM
 * @property Carbon|null $license_expires_on
 * @property string $status
 */
class Employee extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DRIVER = 'driver';

    public const HELPER = 'helper';

    public const POSITIONS = [
        self::DRIVER => 'Supir',
        self::HELPER => 'Kernet',
        'warehouse' => 'Petugas Gudang',
        'admin' => 'Admin',
        'other' => 'Lainnya',
    ];

    protected $fillable = ['code', 'name', 'position', 'phone', 'license_number', 'license_expires_on', 'status'];

    protected function casts(): array
    {
        return ['license_expires_on' => 'date'];
    }
}
