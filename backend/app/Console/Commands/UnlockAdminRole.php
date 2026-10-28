<?php

namespace App\Console\Commands;

use App\Domain\Authorization\Models\Role;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:unlock-admin-role')]
#[Description('Unlock ADMIN role so it can be edited')]
class UnlockAdminRole extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Unlocking ADMIN role...');

        $organizations = $this->tenancy->runAsSystem(function () {
            return \App\Domain\Organization\Models\Organization::all();
        });

        $updated = 0;

        foreach ($organizations as $org) {
            $this->tenancy->runAsSystem(function () use ($org, &$updated) {
                $role = Role::where('organization_id', $org->id)
                    ->where('code', 'ADMIN')
                    ->first();

                if ($role && $role->is_locked) {
                    $role->is_locked = false;
                    $role->save();
                    $updated++;
                    $this->info("  Unlocked ADMIN role for organization: {$org->name}");
                } elseif ($role && !$role->is_locked) {
                    $this->info("  ADMIN role already unlocked for organization: {$org->name}");
                } else {
                    $this->warn("  ADMIN role not found for organization: {$org->name}");
                }
            });
        }

        $this->info("\nDone! Total roles unlocked: {$updated}");
        return 0;
    }
}
