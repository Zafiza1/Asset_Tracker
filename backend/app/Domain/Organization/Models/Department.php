<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $delivery_role driver/helper: its employees can be the crew of a delivery
 * @property string $status
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public const DRIVER = 'driver';

    public const HELPER = 'helper';

    /** Roles a division can have on deliveries. */
    public const DELIVERY_ROLES = [self::DRIVER => 'Supir', self::HELPER => 'Kernet'];

    protected $fillable = ['code', 'name', 'delivery_role', 'status'];

    /**
     * The delivery role a division named Supir or Kernet has (any case, extra spaces ignored); null
     * for any other name. Used where the role is not chosen by hand (Divisi).
     */
    public static function roleFromName(string $name): ?string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        return array_search($name, array_map('mb_strtolower', self::DELIVERY_ROLES), true) ?: null;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
