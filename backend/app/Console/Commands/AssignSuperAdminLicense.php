<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:assign-super-admin-license')]
#[Description('Assign SUPER_ADMIN_FREE license to super admin users')]
class AssignSuperAdminLicense extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Assigning SUPER_ADMIN_FREE license to super admin users...');

        $organizations = $this->tenancy->runAsSystem(function () {
            return \App\Domain\Organization\Models\Organization::all();
        });

        $totalAssigned = 0;

        foreach ($organizations as $org) {
            $this->info("\nProcessing organization: {$org->name} (ID: {$org->id})");

            // Find SUPER_ADMIN_FREE license code
            $code = $this->tenancy->runAsSystem(function () use ($org) {
                return LicenseCode::where('organization_id', $org->id)
                    ->where('reference', 'SUPER_ADMIN_FREE')
                    ->first();
            });

            if (!$code) {
                $this->warn("  No SUPER_ADMIN_FREE license code found for this organization.");
                continue;
            }

            $this->info("  Found license code: {$code->code}");
            $this->info("  Current status: {$code->status}");
            $this->info("  Assigned to: " . ($code->assigned_to_user_id ?? 'none'));

            // Find super admin
            $superAdmin = $this->tenancy->runAsSystem(function () use ($org) {
                return OrganizationMember::where('organization_id', $org->id)
                    ->whereHas('user.roles', function ($q) use ($org) {
                        $q->where('code', 'SUPER_ADMIN')->where('roles.organization_id', $org->id);
                    })
                    ->with('user')
                    ->first();
            });

            if (!$superAdmin) {
                $this->warn("  No super admin found for this organization.");
                continue;
            }

            $this->info("  Found super admin: {$superAdmin->user->email}");

            // Check if already assigned
            if ($code->assigned_to_user_id === $superAdmin->user_id && $code->status === LicenseCode::STATUS_ASSIGNED) {
                $this->info("  License already assigned to this super admin.");
                continue;
            }

            // Assign the license
            try {
                $this->tenancy->runAsSystem(function () use ($code, $superAdmin) {
                    $code->assignTo($superAdmin->user_id);
                });
                $this->info("  Successfully assigned license to super admin!");
                $totalAssigned++;
            } catch (\Exception $e) {
                $this->error("  Failed to assign license: " . $e->getMessage());
            }
        }

        $this->info("\nDone! Total licenses assigned: {$totalAssigned}");
        return 0;
    }
}
