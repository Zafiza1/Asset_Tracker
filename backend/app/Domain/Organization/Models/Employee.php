<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person working for the organization (Master Data › Karyawan). Not an account: drivers and
 * helpers need no login to be on a delivery. Their position is their division (Master Data › Divisi);
 * the division's delivery role says whether they can drive or help on a delivery.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code given by the system (DeliveryMasterService), never entered
 * @property string $name
 * @property string|null $department_id division, which is also the position
 * @property string|null $phone
 * @property string|null $license_number SIM
 * @property Carbon|null $license_expires_on
 * @property string $status
 */
class Employee extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['code', 'name', 'department_id', 'phone', 'license_number', 'license_expires_on', 'status'];

    protected function casts(): array
    {
        return ['license_expires_on' => 'date'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Active employees whose division has one of these delivery roles.
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $roles
     * @return Builder<self>
     */
    public function scopeWithDeliveryRole(Builder $query, array $roles): Builder
    {
        return $query->whereHas('department', fn (Builder $d) => $d->whereIn('delivery_role', $roles));
    }
}
