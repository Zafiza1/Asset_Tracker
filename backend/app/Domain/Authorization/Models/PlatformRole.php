<?php

namespace App\Domain\Authorization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 */
class PlatformRole extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'platform_role_permissions');
    }
}
