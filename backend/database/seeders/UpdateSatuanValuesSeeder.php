<?php

namespace Database\Seeders;

use App\Domain\Assets\Models\Asset;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

class UpdateSatuanValuesSeeder extends Seeder
{
    /**
     * Valid unit options for 'Satuan' attribute.
     */
    private const UNIT_OPTIONS = ['PCS', 'SET', 'MTR', 'LTR', 'RIM', 'BK', 'KG', 'BOX', 'CMS', 'M2', 'JRG', 'M3', 'GLN', 'LBR', 'ROLL', 'PCK', 'SCK'];

    public function run(Tenancy $tenancy): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('UpdateSatuanValuesSeeder must not run in production.');
        }

        $tenancy->runAsSystem(function () use ($tenancy) {
            $organizations = Organization::all();

            if ($organizations->isEmpty()) {
                $this->command?->warn('No organizations found.');
                return;
            }

            foreach ($organizations as $organization) {
                $this->command?->info("Updating satuan values for organization: {$organization->name}");

                $member = \App\Domain\Identity\Models\OrganizationMember::where('organization_id', $organization->id)
                    ->where('status', 'active')
                    ->first();

                if (!$member) {
                    $this->command?->warn("No active member found for {$organization->code}, skipping.");
                    continue;
                }

                $admin = $member->user;

                if (!$admin) {
                    $this->command?->warn("No user found for member in {$organization->code}, skipping.");
                    continue;
                }

                $tenancy->runAsTenant(new TenantContext($organization, $admin), function () {
                    $this->updateSatuanValues();
                });
            }
        });
    }

    private function updateSatuanValues(): void
    {
        $assets = Asset::all();

        if ($assets->isEmpty()) {
            $this->command?->warn('No assets found.');
            return;
        }

        $updatedCount = 0;
        $checkedCount = 0;

        foreach ($assets as $asset) {
            $checkedCount++;

            // Get attribute_values (the model accessor will decode it automatically)
            $attributeValues = $asset->attribute_values ?? [];

            if (!is_array($attributeValues)) {
                $attributeValues = [];
            }

            // Always update satuan to ensure it's valid
            $newValue = $this->getRandomUnit();
            $attributeValues['satuan'] = $newValue;

            // Set as array - the model mutator will encode it automatically
            $asset->attribute_values = $attributeValues;
            $asset->save();
            $updatedCount++;

            // Log first few assets for debugging
            if ($checkedCount <= 3) {
                $this->command?->info("Asset {$asset->name}: satuan set to {$newValue}");
            }
        }

        $this->command?->info("Checked {$checkedCount} assets, updated {$updatedCount} assets with valid satuan values.");
    }

    private function getRandomUnit(): string
    {
        return self::UNIT_OPTIONS[array_rand(self::UNIT_OPTIONS)];
    }
}
