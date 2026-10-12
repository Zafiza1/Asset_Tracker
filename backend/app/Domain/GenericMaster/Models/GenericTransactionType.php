<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalog of transaction types. Their behaviour is implemented in code (Phase 5), so the
 * set of codes is fixed by the seeder; only descriptive fields are data.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class GenericTransactionType extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'sort_order', 'status'];
}
