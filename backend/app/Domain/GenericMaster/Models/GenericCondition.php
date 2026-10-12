<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard asset condition (Baik, Rusak Ringan, ...). Copied into each organization's
 * asset_conditions at provisioning, keeping generic_condition_id as the source.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $severity
 * @property string $status
 */
class GenericCondition extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'severity', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['severity' => 'integer', 'sort_order' => 'integer'];
    }
}
