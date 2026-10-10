<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

final class RoleManagement
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, description?: string|null}  $data
     * @param  list<string>  $permissions
     */
    public function create(array $data, array $permissions): Role
    {
        $this->assertGrantable($permissions);

        return DB::transaction(function () use ($data, $permissions) {
            $role = new Role(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $role->code = strtoupper($data['code']);
            $role->save();
            $this->syncPermissions($role, $permissions);

            $this->audit->record('role.created', $role, after: $this->snapshot($role));

            return $role;
        });
    }

    /**
     * @param  array{name?: string, description?: string|null}  $data
     * @param  list<string>|null  $permissions
     */
    public function update(Role $role, array $data, ?array $permissions): Role
    {
        $this->assertEditable($role);
        if ($permissions !== null) {
            if ($role->is_locked) {
                throw ApiException::unprocessable('ROLE_LOCKED', 'Permission role terkunci tidak dapat diubah.');
            }
            $this->assertGrantable($permissions);
        }

        return DB::transaction(function () use ($role, $data, $permissions) {
            $before = $this->snapshot($role);
            $role->fill($data)->save();
            if ($permissions !== null) {
                $this->syncPermissions($role, $permissions);
            }
            $after = $this->snapshot($role->refresh());
            [$b, $a] = AuditLogger::diff($before, $after);
            if ($a !== []) {
                $this->audit->record('role.updated', $role, before: $b, after: $a);
            }
            $this->tenancy->tenant()->refresh();

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_locked) {
            throw ApiException::unprocessable('ROLE_LOCKED', 'Role terkunci tidak dapat dihapus.');
        }
        $this->assertEditable($role);
        if ($role->users()->exists()) {
            throw ApiException::conflict('ROLE_IN_USE', 'Role masih digunakan oleh pengguna.');
        }

        DB::transaction(function () use ($role) {
            $snapshot = $this->snapshot($role);
            $role->permissions()->detach();
            $role->delete();
            $this->audit->record('role.deleted', 'Role', $role->id, before: $snapshot);
        });
    }

    /**
     * Anti privilege-escalation: an actor can only grant permissions they hold.
     *
     * @param  list<string>  $permissions
     */
    public function assertGrantable(array $permissions): void
    {
        foreach ($permissions as $code) {
            if (! PermissionCatalog::exists($code, PermissionCatalog::SCOPE_TENANT)) {
                throw ApiException::unprocessable('UNKNOWN_PERMISSION', "Permission tidak dikenal: {$code}.");
            }
        }
        $missing = array_values(array_diff($permissions, $this->tenancy->tenant()->permissions()));
        if ($missing !== []) {
            throw new ApiException('PRIVILEGE_ESCALATION', 'Anda tidak dapat memberikan permission yang tidak Anda miliki.', 403, ['permissions' => $missing]);
        }
    }

    /** An actor cannot edit or assign a role that is more powerful than themselves. */
    public function assertEditable(Role $role): void
    {
        $this->assertGrantable($role->permissions()->pluck('code')->all());
    }

    /** @param list<string> $codes */
    private function syncPermissions(Role $role, array $codes): void
    {
        $ids = Permission::query()
            ->where('scope', PermissionCatalog::SCOPE_TENANT)
            ->whereIn('code', array_unique($codes))
            ->pluck('id');

        $role->permissions()->sync($ids->mapWithKeys(fn (string $id) => [$id => ['organization_id' => $role->organization_id]])->all());
    }

    /** @return array<string, mixed> */
    private function snapshot(Role $role): array
    {
        return [
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions()->orderBy('code')->pluck('code')->all(),
        ];
    }
}
