<?php

namespace App\Domain\Authorization\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $template_code
 * @property bool $is_locked
 */
class Role extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['code', 'name', 'description'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('organization_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot('organization_id');
    }
}
