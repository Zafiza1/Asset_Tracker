<?php

namespace App\Domain\Authorization\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $scope_type
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $location_id
 */
class UserDataScope extends Model
{
    use BelongsToOrganization, HasUuids;

    public const TYPES = ['organization', 'branch', 'department', 'location'];

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'scope_type', 'branch_id', 'department_id', 'location_id'];

    /** organization_id is never mass assignable; it is set explicitly here. */
    public static function grant(string $organizationId, string $userId, string $scopeType, ?string $refId = null): self
    {
        $scope = new self([
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'branch_id' => $scopeType === 'branch' ? $refId : null,
            'department_id' => $scopeType === 'department' ? $refId : null,
            'location_id' => $scopeType === 'location' ? $refId : null,
        ]);
        $scope->forceFill(['organization_id' => $organizationId])->save();

        return $scope;
    }

    public function refId(): ?string
    {
        return $this->branch_id ?? $this->department_id ?? $this->location_id;
    }
}
