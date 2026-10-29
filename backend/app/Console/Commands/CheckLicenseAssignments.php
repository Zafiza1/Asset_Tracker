<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:release-license {code=LIC-ERZE-OHSK-ZJ47}')]
#[Description('Release a license code assignment')]
class CheckLicenseAssignments extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $code = $this->argument('code');
        $this->info("Releasing license code: {$code}");

        // Run in system context to bypass tenant scope
        $this->tenancy->runAsSystem(function () use ($code) {
            $licenseCode = LicenseCode::where('code', $code)->first();

            if (!$licenseCode) {
                $this->error("License code {$code} not found in database.");
                return Command::FAILURE;
            }

            $this->info("Found license code: {$licenseCode->code}");
            $this->line("  Status: {$licenseCode->status}");
            $this->line("  Organization ID: {$licenseCode->organization_id}");

            if ($licenseCode->assignedUser) {
                $this->line("  Assigned to: {$licenseCode->assignedUser->email} ({$licenseCode->assignedUser->name})");
                $this->line("  Assigned at: {$licenseCode->assigned_at}");

                $this->warn("\nReleasing license assignment...");
                $licenseCode->release();
                $this->info("License code {$code} has been released successfully.");
                $this->info("Status is now: available");
            } else {
                $this->info("\nThis license is not assigned to any user.");
            }
        });

        return Command::SUCCESS;
    }
}
