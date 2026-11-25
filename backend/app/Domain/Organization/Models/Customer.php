<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Who cylinders are delivered to and picked up from (Master Data › Customer).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string $status
 */
class Customer extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['code', 'name', 'address', 'contact_name', 'phone', 'status'];
}
