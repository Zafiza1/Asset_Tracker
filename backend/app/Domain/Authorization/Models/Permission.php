<?php

namespace App\Domain\Authorization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $scope
 * @property string $group
 * @property string $description
 */
class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'scope', 'group', 'description'];
}
