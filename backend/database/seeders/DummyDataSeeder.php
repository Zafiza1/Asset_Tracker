<?php

namespace Database\Seeders;

use App\Domain\Assets\Models\Asset;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DummyDataSeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DummyDataSeeder must not run in production.');
        }

        $tenancy->runAsSystem(function () use ($tenancy) {
            $organizations = Organization::all();

            if ($organizations->isEmpty()) {
                $this->command?->warn('No organizations found. Please run DemoSeeder first.');
                return;
            }

            foreach ($organizations as $organization) {
                $this->command?->info("Seeding dummy data for organization: {$organization->name}");

                // Get admin user for this organization by checking organization members
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

                $tenancy->runAsTenant(new TenantContext($organization, $admin), function () use ($organization) {
                    $this->seedLocations($organization);
                    $this->seedAssets($organization);
                });
            }
        });
    }

    private function seedLocations(Organization $organization): void
    {
        $locationTypes = LocationType::all();
        $locationTypeCodes = $locationTypes->pluck('code')->toArray();

        if (empty($locationTypeCodes)) {
            $this->command?->warn('No location types found. Skipping location seeding.');
            return;
        }

        $existingCount = Location::where('organization_id', $organization->id)->count();
        if ($existingCount >= 100) {
            $this->command?->info("Organization already has {$existingCount} locations, skipping.");
            return;
        }

        $this->command?->info('Creating 100 dummy locations...');

        $prefixes = ['GDG', 'KTR', 'CAB', 'WRS', 'GRL', 'TOK', 'LAB', 'PAB', 'PST', 'HUB'];
        $names = ['Gudang', 'Kantor', 'Cabang', 'Workshop', 'Gudang Luar', 'Toko', 'Laboratorium', 'Pabrik', 'Pusat', 'Hub'];
        $cities = ['Jakarta', 'Surabaya', 'Bandung', 'Medan', 'Semarang', 'Makassar', 'Denpasar', 'Yogyakarta', 'Palembang', 'Balikpapan'];

        $locations = [];
        for ($i = 0; $i < 100; $i++) {
            $typeIndex = array_rand($locationTypeCodes);
            $prefixIndex = array_rand($prefixes);
            $nameIndex = array_rand($names);
            $cityIndex = array_rand($cities);

            $code = $prefixes[$prefixIndex] . '-' . strtoupper(substr($cities[$cityIndex], 0, 3)) . sprintf('%03d', $i + 1);
            $name = $names[$nameIndex] . ' ' . $cities[$cityIndex] . ' ' . ($i + 1);
            $locationTypeId = $locationTypes[$typeIndex]->id;

            $locations[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $organization->id,
                'location_type_id' => $locationTypeId,
                'code' => $code,
                'name' => $name,
                'status' => 'active',
                'path' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Batch insert
        DB::table('locations')->insert($locations);

        // Update paths after insertion (since they depend on IDs)
        $insertedLocations = Location::where('organization_id', $organization->id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        foreach ($insertedLocations as $location) {
            $location->path = '/' . $location->id . '/';
            $location->save();
        }

        $this->command?->info('100 dummy locations created successfully.');
    }

    private function seedAssets(Organization $organization): void
    {
        $categories = TenantAssetCategory::where('organization_id', $organization->id)->get();
        $locations = Location::where('organization_id', $organization->id)->get();

        if ($categories->isEmpty()) {
            $this->command?->warn('No categories found. Skipping asset seeding.');
            return;
        }

        if ($locations->isEmpty()) {
            $this->command?->warn('No locations found. Skipping asset seeding.');
            return;
        }

        // Get all attributes for all categories
        $categoryAttributes = [];
        foreach ($categories as $category) {
            $attrs = TenantCategoryAttribute::where('category_id', $category->id)
                ->where('status', 'active')
                ->get();
            $categoryAttributes[$category->id] = $attrs;
        }

        $existingCount = Asset::where('organization_id', $organization->id)->count();

        if ($existingCount >= 200) {
            $this->command?->info("Updating all {$existingCount} existing assets with attribute values...");
            $this->updateExistingAssets($organization, $categoryAttributes);
            return;
        }

        $this->command?->info('Creating 200 dummy assets...');

        $statuses = ['available', 'in_use', 'on_loan', 'under_maintenance', 'disposed'];
        $assetNames = [
            'Laptop Dell Latitude', 'Laptop HP ProBook', 'Laptop Lenovo ThinkPad', 'MacBook Pro',
            'Desktop PC', 'Monitor LG 24"', 'Monitor Samsung 27"', 'Printer Epson',
            'Printer HP LaserJet', 'Scanner Canon', 'Projector Epson', 'Router Cisco',
            'Switch Network', 'Server Dell PowerEdge', 'NAS Synology', 'Tablet iPad',
            'Smartphone Samsung', 'Camera DSLR', 'Webcam Logitech', 'Keyboard Mechanical',
            'Mouse Wireless', 'Headset Jabra', 'Speaker Bluetooth', 'UPS APC',
            'Mobil Toyota Avanza', 'Mobil Honda Jazz', 'Mobil Suzuki Ertiga', 'Motor Honda Vario',
            'Motor Yamaha NMAX', 'Truck Mitsubishi', 'Van Toyota Hiace', 'Forklift Toyota',
            'Meja Kantor', 'Kursi Ergonomis', 'Lemari Arsip', 'Rak Besi',
            'Whiteboard', 'AC Split', 'Kipas Angin', 'Lampu LED',
            'Mesin Kopi', 'Water Dispenser', 'Microwave', 'Kulkas Mini',
            'Mesin Fotocopy', 'Mesin Absensi', 'CCTV Camera', 'Access Control',
            'Generator Set', 'Panel Listrik', 'Trafo', 'Kabel LAN',
            'Tool Kit', 'Bor Listrik', 'Gerinda', 'Las Listrik',
        ];

        $assets = [];
        for ($i = 0; $i < 200; $i++) {
            $category = $categories->random();
            $location = $locations->random();
            $status = $statuses[array_rand($statuses)];
            $name = $assetNames[array_rand($assetNames)] . ' ' . sprintf('%04d', $i + 1);

            $acquisitionCost = rand(1000000, 50000000);
            $monthsAgo = rand(1, 36);

            // Generate attribute values based on category attributes
            $attributeValues = [];
            if (isset($categoryAttributes[$category->id])) {
                foreach ($categoryAttributes[$category->id] as $attr) {
                    $attributeValues[$attr->key] = $this->generateAttributeValue($attr->data_type, $attr->options);
                }
            }

            $assets[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $organization->id,
                'asset_number' => 'AST-' . strtoupper(substr($organization->code, 0, 3)) . '-' . sprintf('%06d', $i + 1),
                'serial_number' => 'SN-' . date('Y') . '-' . sprintf('%06d', $i + 1),
                'name' => $name,
                'category_id' => $category->id,
                'location_id' => $location->id,
                'status' => $status,
                'acquisition_date' => now()->subMonths($monthsAgo)->toDateString(),
                'acquisition_cost' => $acquisitionCost,
                'description' => null,
                'attribute_values' => json_encode((object) $attributeValues),
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Batch insert
        DB::table('assets')->insert($assets);

        $this->command?->info('200 dummy assets created successfully.');
    }

    /**
     * Update existing assets with attribute values.
     */
    private function updateExistingAssets(Organization $organization, array $categoryAttributes): void
    {
        $assets = Asset::where('organization_id', $organization->id)->get();

        $updatedCount = 0;
        foreach ($assets as $asset) {
            $attributeValues = [];
            if (isset($categoryAttributes[$asset->category_id])) {
                foreach ($categoryAttributes[$asset->category_id] as $attr) {
                    $attributeValues[$attr->key] = $this->generateAttributeValue($attr->data_type, $attr->options);
                }
            }

            // Update if there are attributes to set
            if (!empty($attributeValues)) {
                $asset->attribute_values = $attributeValues;
                $asset->save();
                $updatedCount++;
            }
        }

        $this->command?->info("Updated {$updatedCount} assets with attribute values.");
    }

    /**
     * Generate a dummy value for an attribute based on its data type.
     */
    private function generateAttributeValue(string $dataType, ?array $options): mixed
    {
        return match ($dataType) {
            'string' => 'Sample value ' . rand(1, 100),
            'text' => 'Sample text value ' . rand(1, 100),
            'integer' => rand(1, 1000),
            'decimal' => rand(1, 1000) / 10,
            'boolean' => (bool) rand(0, 1),
            'date' => now()->subDays(rand(1, 365))->toDateString(),
            'enum' => $options && !empty($options) ? $options[array_rand($options)] ?? null : null,
            default => null,
        };
    }
}
