<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Invariant: an organization always keeps at least one active user holding the locked
 * ORG_ADMIN role, so it can never lock itself out.
 */
final class OrgAdminGuard
{
    /** Serialize admin-affecting changes per organization (call inside a DB transaction). */
    public function lock(string $organizationId): void
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
    }

    public function assertHasActiveAdmin(string $organizationId): void
    {
        $count = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('user_roles.organization_id', $organizationId)
            ->where('roles.template_code', RoleTemplates::ORG_ADMIN)
            ->where('roles.is_locked', true)
            ->where('users.status', User::STATUS_ACTIVE)
            ->distinct()
            ->count('users.id');

        if ($count < 1) {
            throw ApiException::conflict('LAST_ORG_ADMIN', 'Organisasi harus memiliki minimal satu Administrator Organisasi yang aktif.');
        }
    }
}
