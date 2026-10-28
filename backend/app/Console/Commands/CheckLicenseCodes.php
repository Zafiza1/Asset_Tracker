<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-license-codes')]
#[Description('Check license codes status in database')]
class CheckLicenseCodes extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Checking license codes in database...');

        $organizations = $this->tenancy->runAsSystem(function () {
            return \App\Domain\Organization\Models\Organization::all();
        });

        foreach ($organizations as $org) {
            $this->info("\nOrganization: {$org->name} (ID: {$org->id})");

            $codes = $this->tenancy->runAsSystem(function () use ($org) {
                return LicenseCode::where('organization_id', $org->id)
                    ->with('assignedUser')
                    ->get();
            });

            foreach ($codes as $code) {
                $this->info("  Code: {$code->code}");
                $this->info("    Reference: {$code->reference}");
                $this->info("    Status: {$code->status}");
                $this->info("    Assigned to user ID: {$code->assigned_to_user_id}");
                $this->info("    Assigned user: " . ($code->assignedUser ? $code->assignedUser->email : 'null'));
                $this->info("    Is available: " . ($code->isAvailable() ? 'yes' : 'no'));
                $this->info("    Is assigned: " . ($code->isAssigned() ? 'yes' : 'no'));
            }
        }

        return 0;
    }
}
