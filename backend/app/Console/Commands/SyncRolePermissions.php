<?php

namespace App\Console\Commands;

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Authorization\Models\Role;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-role-permissions')]
#[Description('Sync role permissions according to role templates')]
class SyncRolePermissions extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Syncing role permissions...');

        $organizations = $this->tenancy->runAsSystem(function () {
            return \App\Domain\Organization\Models\Organization::all();
        });

        foreach ($organizations as $org) {
            $this->info("\nProcessing organization: {$org->name}");

            $this->tenancy->runAsSystem(function () use ($org) {
                $templates = RoleTemplates::tenant();

                foreach ($templates as $code => $template) {
                    $role = Role::where('organization_id', $org->id)
                        ->where('code', $code)
                        ->first();

                    if (!$role) {
                        $this->warn("  Role {$code} not found, skipping...");
                        continue;
                    }

                    $permissionIds = \App\Domain\Authorization\Models\Permission::whereIn('code', $template['permissions'])->pluck('id');
                    $syncData = [];
                    foreach ($permissionIds as $permissionId) {
                        $syncData[$permissionId] = ['organization_id' => $org->id];
                    }
                    $role->permissions()->sync($syncData);

                    $this->info("  Synced {$code} with " . count($template['permissions']) . ' permissions');
                }
            });
        }

        $this->info("\nDone! Role permissions synced successfully.");
        return 0;
    }
}
