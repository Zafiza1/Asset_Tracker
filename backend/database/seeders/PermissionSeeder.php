<?php

namespace Database\Seeders;

use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\PlatformRole;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use Illuminate\Database\Seeder;

/** Idempotent: syncs the permission catalog and platform role templates. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        PermissionCatalog::sync();

        foreach (RoleTemplates::platform() as $code => $template) {
            $role = PlatformRole::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $template['name'], 'description' => $template['description']],
            );
            $role->permissions()->sync(Permission::query()->whereIn('code', $template['permissions'])->pluck('id'));
        }
    }
}
