<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AddDefaultAttributesToExistingCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy, CategoryAttributeService $attributesService): void
    {
        // Categories of every organization: system context lifts the tenant scope and RLS.
        $tenancy->runAsSystem(function () use ($attributesService) {
            $this->command->info('Adding default attributes to existing categories...');

            $categories = TenantAssetCategory::query()
                ->where('status', '!=', 'archived')
                ->get();

            foreach ($categories as $category) {
                // Check if category already has default attributes
                $hasDefaults = TenantCategoryAttribute::query()
                    ->where('category_id', $category->id)
                    ->where('is_default', true)
                    ->exists();

                if ($hasDefaults) {
                    $this->command->line("  Skipping category {$category->name} (already has default attributes)");
                    continue;
                }

                // Create default attributes
                DB::transaction(function () use ($category, $attributesService) {
                    $attributesService->createDefaultAttributes($category);
                });

                $this->command->line("  Added default attributes to category: {$category->name}");
            }

            $this->command->info('Default attributes added successfully.');
        });
    }
}
