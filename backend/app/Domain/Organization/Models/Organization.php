<?php

namespace App\Domain\Organization\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    /** Editable profile fields; `code` and `status` change only through dedicated platform actions. */
    public const PROFILE_FIELDS = ['name', 'legal_name', 'timezone', 'locale', 'currency', 'email', 'phone', 'tax_id', 'address'];

    protected $fillable = self::PROFILE_FIELDS;

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function settings(): HasOne
    {
        return $this->hasOne(OrganizationSetting::class);
    }
}
