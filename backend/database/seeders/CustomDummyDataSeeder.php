<?php

namespace Database\Seeders;

use App\Domain\Assets\Models\Asset;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
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

class CustomDummyDataSeeder extends Seeder
{
    public function run(Tenancy $tenancy): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('CustomDummyDataSeeder must not run in production.');
        }

        $tenancy->runAsSystem(function () use ($tenancy) {
            $organizations = Organization::all();

            if ($organizations->isEmpty()) {
                $this->command?->warn('No organizations found. Please run DemoSeeder first.');
                return;
            }

            foreach ($organizations as $organization) {
                $this->command?->info("Seeding custom dummy data for organization: {$organization->name}");

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
                    $this->seedCategories($organization);
                    $this->seedLocations($organization);
                    $this->seedAssets($organization);
                });
            }
        });
    }

    private function seedCategories(Organization $organization): void
    {
        $existingCount = TenantAssetCategory::where('organization_id', $organization->id)->count();

        if ($existingCount >= 20) {
            $this->command?->info("Organization already has {$existingCount} categories, skipping category seeding.");
            return;
        }

        $this->command?->info('Creating 20 dummy categories...');

        $genericCategories = GenericAssetCategory::all();

        if ($genericCategories->isEmpty()) {
            $this->command?->warn('No generic categories found. Please run GenericCatalogSeeder first.');
            return;
        }

        $categorySpecs = [
            ['code' => 'LAPTOP', 'name' => 'Laptop & Notebook', 'generic' => 'PERANGKAT_IT'],
            ['code' => 'DESKTOP', 'name' => 'Desktop PC', 'generic' => 'PERANGKAT_IT'],
            ['code' => 'MONITOR', 'name' => 'Monitor Display', 'generic' => 'PERANGKAT_IT'],
            ['code' => 'PRINTER', 'name' => 'Printer & Scanner', 'generic' => 'PERANGKAT_IT'],
            ['code' => 'SERVER', 'name' => 'Server & Network', 'generic' => 'PERANGKAT_IT'],
            ['code' => 'MOBIL', 'name' => 'Mobil Penumpang', 'generic' => 'KENDARAAN'],
            ['code' => 'MOTOR', 'name' => 'Sepeda Motor', 'generic' => 'KENDARAAN'],
            ['code' => 'TRUK', 'name' => 'Truk & Van', 'generic' => 'KENDARAAN'],
            ['code' => 'ALAT_BERAT', 'name' => 'Alat Berat', 'generic' => 'KONSTRUKSI'],
            ['code' => 'MEJA', 'name' => 'Meja Kantor', 'generic' => 'PERALATAN_KANTOR'],
            ['code' => 'KURSI', 'name' => 'Kursi & Sofa', 'generic' => 'PERALATAN_KANTOR'],
            ['code' => 'LEMARI', 'name' => 'Lemari & Rak', 'generic' => 'PERALATAN_KANTOR'],
            ['code' => 'MESIN_PROD', 'name' => 'Mesin Produksi', 'generic' => 'MESIN'],
            ['code' => 'TABUNG_GAS', 'name' => 'Tabung Gas', 'generic' => 'GAS'],
            ['code' => 'ALAT_MEDIS', 'name' => 'Peralatan Medis', 'generic' => 'PERALATAN_MEDIS'],
            ['code' => 'SOFTWARE', 'name' => 'Lisensi Software', 'generic' => 'ASET_DIGITAL'],
            ['code' => 'AC', 'name' => 'AC & Pendingin', 'generic' => 'UMUM'],
            ['code' => 'KAMERA', 'name' => 'Kamera & CCTV', 'generic' => 'UMUM'],
            ['code' => 'TOOLS', 'name' => 'Alat Teknik', 'generic' => 'UMUM'],
            ['code' => 'LAINNYA', 'name' => 'Aset Lainnya', 'generic' => 'UMUM'],
        ];

        $categories = [];
        foreach ($categorySpecs as $spec) {
            $genericCategory = $genericCategories->firstWhere('code', $spec['generic']);
            if (!$genericCategory) {
                continue;
            }

            $categories[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $organization->id,
                'generic_category_id' => $genericCategory->id,
                'code' => $spec['code'],
                'name' => $spec['name'],
                'status' => 'active',
                'path' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('tenant_asset_categories')->insert($categories);

        $insertedCategories = TenantAssetCategory::where('organization_id', $organization->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        foreach ($insertedCategories as $category) {
            $category->path = '/' . $category->id . '/';
            $category->save();
        }

        $this->command?->info('20 dummy categories created successfully.');
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

        if ($existingCount >= 30) {
            $this->command?->info("Organization already has {$existingCount} locations, skipping location seeding.");
            return;
        }

        $this->command?->info('Creating 30 dummy locations...');

        $prefixes = ['GDG', 'KTR', 'CAB', 'WRS', 'GRL', 'TOK', 'LAB', 'PAB', 'PST', 'HUB'];
        $names = ['Gudang', 'Kantor', 'Cabang', 'Workshop', 'Gudang Luar', 'Toko', 'Laboratorium', 'Pabrik', 'Pusat', 'Hub'];
        $cities = ['Jakarta', 'Surabaya', 'Bandung', 'Medan', 'Semarang', 'Makassar', 'Denpasar', 'Yogyakarta', 'Palembang', 'Balikpapan'];

        $locations = [];
        for ($i = 0; $i < 30; $i++) {
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

        DB::table('locations')->insert($locations);

        $insertedLocations = Location::where('organization_id', $organization->id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        foreach ($insertedLocations as $location) {
            $location->path = '/' . $location->id . '/';
            $location->save();
        }

        $this->command?->info('30 dummy locations created successfully.');
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

        $categoryAttributes = [];
        foreach ($categories as $category) {
            $attrs = TenantCategoryAttribute::where('category_id', $category->id)
                ->where('status', 'active')
                ->get();
            $categoryAttributes[$category->id] = $attrs;
        }

        $existingCount = Asset::where('organization_id', $organization->id)->count();

        if ($existingCount >= 500) {
            $this->command?->info("Organization already has {$existingCount} assets, skipping asset seeding.");
            return;
        }

        $this->command?->info('Creating 500 dummy assets...');

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
            'Excavator', 'Bulldozer', 'Crane', 'Loader',
            'Tabung Gas Oksigen', 'Tabung Gas Nitrogen', 'Regulator Gas', 'Manometer',
            'Peralatan Bedah', 'USG Machine', 'X-Ray Machine', 'Patient Monitor',
            'Lisensi Windows', 'Lisensi Office', 'Lisensi Adobe', 'Lisensi Antivirus',
            'Telepon IP', 'PABX System', 'Video Conference', 'Smart TV',
            'Mesin Jilid', 'Mesin Hitung Uang', 'Laminator', 'Shredder',
            'Sofa Ruang Tunggu', 'Partisi Kantor', 'Brankas', 'Filling Cabinet',
            'Mesin Cuci', 'Dispenser Air', 'Kulkas', 'Microwave Oven',
            'Drone', 'GPS Tracker', 'Thermal Camera', 'Night Vision',
        ];

        $assets = [];
        for ($i = 0; $i < 500; $i++) {
            $category = $categories->random();
            $location = $locations->random();
            $status = $statuses[array_rand($statuses)];
            $name = $assetNames[array_rand($assetNames)] . ' ' . sprintf('%04d', $i + 1);

            $acquisitionCost = rand(1000000, 50000000);
            $monthsAgo = rand(1, 36);

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

        DB::table('assets')->insert($assets);

        $this->command?->info('500 dummy assets created successfully.');
    }

    private function generateAttributeValue(string $dataType, ?array $options): mixed
    {
        return match ($dataType) {
            'string' => 'Sample value ' . rand(1, 100),
            'text' => 'Sample text value ' . rand(1, 100),
            'integer' => rand(1, 1000),
            'decimal' => rand(1, 1000) / 10,
            'boolean' => (bool) rand(0, 1),
            'date' => now()->subDays(rand(1, 365))->toDateString(),
            'enum' => $options && !empty($options) ? ($options[array_rand($options)]['value'] ?? null) : null,
            default => null,
        };
    }
}
