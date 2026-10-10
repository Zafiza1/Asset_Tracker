<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-owned generic master (read-only for tenants).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class LocationType extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'status'];
}
