<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('app:generate-missing-license-codes')]
#[Description('Generate license codes for licenses that dont have them')]
class GenerateMissingLicenseCodes extends Command
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to generate missing license codes...');

        // Step 1: Add reference column if it doesn't exist
        $this->info('Checking for reference column...');
        try {
            $hasColumn = Schema::hasColumn('license_codes', 'reference');
            if (!$hasColumn) {
                $this->info('Adding reference column to license_codes table...');
                DB::statement("ALTER TABLE license_codes ADD COLUMN reference VARCHAR(255) NULL");
                $this->info('Reference column added successfully.');
            } else {
                $this->info('Reference column already exists.');
            }
        } catch (\Exception $e) {
            $this->error('Failed to add reference column: ' . $e->getMessage());
            return 1;
        }

        // Step 2: Get all organizations using system context
        $this->info('Fetching all organizations...');
        $organizations = $this->tenancy->runAsSystem(function () {
            return Organization::all();
        });
        $this->info('Found ' . $organizations->count() . ' organizations.');

        $totalCreated = 0;

        foreach ($organizations as $org) {
            $this->info("Processing organization: {$org->name} (ID: {$org->id})");

            // Get all licenses for this organization
            $licenses = $this->tenancy->runAsSystem(function () use ($org) {
                return OrganizationLicense::where('organization_id', $org->id)->get();
            });
            $this->info("  Found {$licenses->count()} licenses.");

            // Get existing license codes
            $existingCodes = $this->tenancy->runAsSystem(function () use ($org) {
                return LicenseCode::where('organization_id', $org->id)->pluck('reference')->toArray();
            });
            $this->info("  Existing license codes: " . count($existingCodes));

            $createdCount = 0;
            $assignedCount = 0;

            foreach ($licenses as $license) {
                if ($license->reference && !in_array($license->reference, $existingCodes)) {
                    try {
                        $code = $this->tenancy->runAsSystem(function () use ($org, $license) {
                            return LicenseCodeService::createForOrganization(
                                $org,
                                $license->notes,
                                $license->reference
                            );
                        });
                        $this->info("    Created license code: {$code->code} for reference: {$license->reference}");
                        $createdCount++;
                        $totalCreated++;

                        // Auto-assign SUPER_ADMIN_FREE to the first super admin
                        if ($license->reference === 'SUPER_ADMIN_FREE') {
                            $superAdmin = $this->tenancy->runAsSystem(function () use ($org) {
                                return OrganizationMember::where('organization_id', $org->id)
                                    ->whereHas('user.roles', function ($q) use ($org) {
                                        $q->where('code', 'SUPER_ADMIN')->where('roles.organization_id', $org->id);
                                    })
                                    ->with('user')
                                    ->first();
                            });
                            if ($superAdmin && $code->isAvailable() && $superAdmin->user) {
                                $code->assignTo($superAdmin->user_id);
                                $this->info("    Auto-assigned to super admin: {$superAdmin->user->email}");
                                $assignedCount++;
                            }
                        }
                    } catch (\Exception $e) {
                        $this->error("    Failed to create code for reference {$license->reference}: " . $e->getMessage());
                    }
                } elseif ($license->reference === 'SUPER_ADMIN_FREE') {
                    // Check if existing SUPER_ADMIN_FREE code needs to be assigned
                    $code = $this->tenancy->runAsSystem(function () use ($org, $license) {
                        return LicenseCode::where('organization_id', $org->id)
                            ->where('reference', $license->reference)
                            ->first();
                    });
                    if ($code && $code->isAvailable()) {
                        $superAdmin = $this->tenancy->runAsSystem(function () use ($org) {
                            return OrganizationMember::where('organization_id', $org->id)
                                ->whereHas('user.roles', function ($q) use ($org) {
                                    $q->where('code', 'SUPER_ADMIN')->where('roles.organization_id', $org->id);
                                })
                                ->with('user')
                                ->first();
                        });
                        if ($superAdmin && $superAdmin->user) {
                            $code->assignTo($superAdmin->user_id);
                            $this->info("    Assigned existing code to super admin: {$superAdmin->user->email}");
                            $assignedCount++;
                        }
                    }
                }
            }

            $this->info("  Created {$createdCount} license codes for this organization.");
            $this->info("  Assigned {$assignedCount} license codes to super admin.");
        }

        $this->info('Done! Total license codes created: ' . $totalCreated);
        return 0;
    }
}
