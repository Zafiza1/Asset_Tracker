<?php

namespace Database\Seeders;

use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Services\AssetService;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Services\DeliveryService;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserManagement;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Organization\Models\Vehicle;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\CategoryModule;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dummy data of the PT SIG / PT CDO edition: two separate organizations that share the Super Admin
 * of PT SIG (registered on the web with code PTSIGCDO), each with the category Tabung, divisions,
 * employees, vehicles, customers, cylinders and deliveries in several stages. Everything goes through the services the API uses.
 *
 *     php artisan db:seed --database=pgsql_owner --class=GasCylinderSeeder
 *
 * Runs once per organization (skipped when its dummy cylinders exist); refuses to run in production.
 */
class GasCylinderSeeder extends Seeder
{
    private const CYLINDERS = 40;

    /** Marks what this seeder made, in the Catatan of the cylinder. */
    private const MARK = 'Data dummy';

    private const ORGANIZATIONS = [
        'PTSIGCDO' => ['name' => 'PT SIG', 'unit' => 'PT_SIG', 'prefix' => 'SIG', 'plate' => 'L', 'base' => [-7.2575, 112.7521],
            'customers' => ['RS Husada Utama', 'RSUD Dr. Soetomo', 'Bengkel Las Jaya Teknik', 'PT Baja Perkasa', 'Klinik Medika Sejahtera', 'CV Karya Logam'],
            'drivers' => ['Budi Santoso', 'Agus Wijaya', 'Slamet Riyadi', 'Hendra Kurniawan'],
            'helpers' => ['Andi Pratama', 'Rizky Maulana', 'Dedi Saputra', 'Fajar Nugroho']],
        'PTCDO' => ['name' => 'PT CDO', 'unit' => 'PT_CDO', 'prefix' => 'CDO', 'plate' => 'W', 'base' => [-7.4478, 112.7183],
            'customers' => ['RS Delta Surya', 'PT Pangan Beku Nusantara', 'Bengkel Las Sumber Makmur', 'PT Es Kristal Abadi', 'CV Minuman Segar', 'RS Mitra Keluarga'],
            'drivers' => ['Joko Susilo', 'Wahyu Hidayat', 'Bambang Setiawan', 'Eko Prasetyo'],
            'helpers' => ['Rudi Hartono', 'Yusuf Ramadhan', 'Imam Syafii', 'Doni Firmansyah']],
    ];

    /** Nama Produk, Jenis Gas, Grade Gas, Warna, Volume, Satuan Volume, Jenis Tabung */
    private const PRODUCTS = [
        ['Oksigen Medis 6 m3', 'Oksigen (O2)', 'Medis', 'Putih', 6, 'M3', 'Besar'],
        ['Oksigen Industri 6 m3', 'Oksigen (O2)', 'Industri', 'Biru', 6, 'M3', 'Besar'],
        ['Oksigen Medis 1 m3', 'Oksigen (O2)', 'Medis', 'Putih', 1, 'M3', 'Kecil'],
        ['Nitrogen HP 7 m3', 'Nitrogen (N2)', 'High Purity', 'Abu-abu', 7, 'M3', 'Besar'],
        ['Argon Welding 7 m3', 'Argon (Ar)', 'Welding', 'Hijau Tua', 7, 'M3', 'Besar'],
        ['CO2 Food Grade 40 L', 'Karbon Dioksida (CO2)', 'Food Grade', 'Abu-abu Tua', 40, 'LITER', 'Besar'],
        ['Asetilen 40 L', 'Asetilen (C2H2)', 'Industri', 'Merah', 40, 'LITER', 'Besar'],
        ['Oksigen Portabel 10 L', 'Oksigen (O2)', 'Medis', 'Putih', 10, 'LITER', 'Portabel'],
    ];

    public function run(Tenancy $tenancy): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('GasCylinderSeeder must not run in production.');
        }
        foreach (self::ORGANIZATIONS as $code => $spec) {
            [$organization, $admin] = $tenancy->runAsSystem(fn () => $this->organization($code, $spec));
            $this->join($tenancy, $organization, $admin);
            $tenancy->runAsTenant(new TenantContext($organization, $admin), fn () => DB::transaction(fn () => $this->seed($organization, $spec)));
        }
    }

    /** @return array{0: Organization, 1: User} the organization and the Super Admin of PT SIG */
    private function organization(string $code, array $spec): array
    {
        $main = Organization::query()->where('code', array_key_first(self::ORGANIZATIONS))->first()
            ?? throw new RuntimeException('Business Unit PTSIGCDO belum ada. Daftarkan PT SIG di web dengan kode itu terlebih dahulu.');
        $admin = User::query()->whereHas('roles', fn ($q) => $q->where('roles.organization_id', $main->id)->where('roles.code', RoleTemplates::SUPER_ADMIN))->firstOrFail();

        $organization = Organization::query()->where('code', $code)->first();
        if ($organization === null) {
            $organization = app(OrganizationProvisioner::class)->provision(['code' => $code, 'name' => $spec['name']], $admin)['organization'];
            app(Licensing::class)->grantTrial($organization, $admin);
            $this->command?->info("Business Unit {$spec['name']} dibuat; Super Admin PT SIG menjadi Super Admin-nya.");
        }

        return [$organization->refresh(), $admin];
    }

    /** An organization made earlier by another Super Admin takes the one of PT SIG in, as Super Admin. */
    private function join(Tenancy $tenancy, Organization $organization, User $admin): void
    {
        $host = $tenancy->runAsSystem(function () use ($organization, $admin) {
            $member = OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $admin->id)->exists();

            return $member ? null : User::query()->whereHas('roles', fn ($q) => $q->where('roles.organization_id', $organization->id)->where('roles.code', RoleTemplates::SUPER_ADMIN))->firstOrFail();
        });
        if ($host === null) {
            return;
        }
        $tenancy->runAsTenant(new TenantContext($organization, $host), fn () => app(UserManagement::class)->addMember(
            $admin, [], [Role::query()->where('code', RoleTemplates::SUPER_ADMIN)->value('id')], [['scope_type' => 'organization']], ['seeder' => self::class],
        ));
        $this->command?->info("{$admin->email} menjadi Super Admin {$organization->name}.");
    }

    private function seed(Organization $organization, array $spec): void
    {
        $cylinder = $this->categories($organization);
        $this->barcode([$cylinder]);
        $this->driverRole();
        if (Asset::query()->where('serial_number', "{$spec['prefix']}-TBG-0001")->exists()) {
            $this->command?->info("{$spec['name']}: data dummy sudah ada, dilewati.");

            return;
        }

        $customers = $this->customers($spec);
        [$drivers, $helpers] = $this->employees($spec);
        $vehicles = $this->vehicles($spec);
        $cylinders = $this->cylinders($cylinder, $spec);
        $this->deliveries($spec, $customers, $drivers, $helpers, $vehicles, $cylinders);
        $this->command?->info("{$spec['name']}: ".count($cylinders).' tabung, '.count($vehicles).' kendaraan, '.(count($drivers) + count($helpers) + 2).' karyawan, '.count($customers).' customer, 5 transaksi.');
    }

    /** Tabung; the other standard categories are archived unless assets use them. Vehicles are not assets here. */
    private function categories(Organization $organization): TenantAssetCategory
    {
        $service = app(TenantCategoryService::class);
        $find = fn (string $code) => TenantAssetCategory::query()->where('code', $code)->first();
        $cylinder = $find(StandardCategories::CYLINDER) ?? $service->installOne($organization->id, StandardCategories::CYLINDER, StandardCategories::cylinder());

        $others = array_keys(StandardCategories::all());
        foreach (TenantAssetCategory::query()->whereIn('code', $others)->where('status', '!=', 'archived')->get() as $category) {
            try {
                $service->archive($category, 'Edisi PT SIG / PT CDO hanya memakai kategori Tabung.');
            } catch (ApiException $e) {
                $this->command?->warn("Kategori {$category->name} tidak diarsipkan: {$e->getMessage()}");
            }
        }

        return $cylinder;
    }

    /** @param list<TenantAssetCategory> $categories */
    private function barcode(array $categories): void
    {
        OrganizationModule::query()->firstOrCreate(['module' => OrganizationModule::BARCODE]);
        foreach ($categories as $category) {
            CategoryModule::query()->firstOrCreate(['category_id' => $category->id, 'module' => OrganizationModule::BARCODE]);
        }
    }

    /** A role for the phone only: sees deliveries, scans, and reports on the way. */
    private function driverRole(): void
    {
        if (Role::query()->where('code', 'SUPIR')->exists()) {
            return;
        }
        $role = Role::query()->create(['code' => 'SUPIR', 'name' => 'Supir / Kernet', 'description' => 'Scan muat/bongkar dan lapor perjalanan di mobile.']);
        $role->permissions()->attach(Permission::query()->whereIn('code', ['delivery.view', 'delivery.operate'])->pluck('id')
            ->mapWithKeys(fn (string $id) => [$id => ['organization_id' => $role->organization_id]])->all());
    }

    /** @return list<Customer> */
    private function customers(array $spec): array
    {
        $streets = ['Jl. Raya Darmo', 'Jl. Ahmad Yani', 'Jl. Rungkut Industri', 'Jl. Basuki Rahmat', 'Jl. Diponegoro', 'Jl. Margomulyo'];

        return array_map(fn (string $name, int $i) => app(DeliveryMasterService::class)->createCustomer([
            'code' => sprintf('%s-CUST-%02d', $spec['prefix'], $i + 1), 'name' => $name, 'address' => $streets[$i].' No. '.(10 + $i * 7),
            'contact_name' => ['Ibu Sri', 'Pak Anton', 'Pak Yudi', 'Ibu Rina', 'dr. Maya', 'Pak Tono'][$i], 'phone' => '0812-0000-1'.sprintf('%03d', $i + 1),
        ]), $spec['customers'], array_keys($spec['customers']));
    }

    /** @return array{0: list<Employee>, 1: list<Employee>} drivers and helpers */
    private function employees(array $spec): array
    {
        $division = fn (string $name, string $code, ?string $role) => Department::query()->where('code', $code)->first()
            ?? app(OrganizationStructureService::class)->createDepartment(['code' => $code, 'name' => $name, 'delivery_role' => $role]);
        $divisions = [
            'driver' => $division('Supir', 'SUPIR', Department::DRIVER),
            'helper' => $division('Kernet', 'KERNET', Department::HELPER),
            'admin' => $division('Admin', 'ADMIN', null),
            'warehouse' => $division('Petugas Gudang', 'GUDANG', null),
        ];
        $number = 0;
        $create = function (string $name, string $position) use ($spec, $divisions, &$number) {
            $number++;
            $license = $position === 'driver' ? [
                'license_number' => sprintf('9%03d-0000-%06d', $number, $number),
                'license_expires_on' => now()->addMonths(6 + $number * 5)->toDateString(),
            ] : [];

            return app(DeliveryMasterService::class)->createEmployee([
                'code' => sprintf('%s-%04d', $spec['prefix'], $number), 'name' => $name, 'department_id' => $divisions[$position]->id,
                'phone' => '0813-0000-2'.sprintf('%03d', $number), ...$license,
            ]);
        };
        $drivers = array_map(fn (string $name) => $create($name, 'driver'), $spec['drivers']);
        $helpers = array_map(fn (string $name) => $create($name, 'helper'), $spec['helpers']);
        $create('Siti Aminah', 'admin');
        $create('Supriyadi', 'warehouse');

        return [$drivers, $helpers];
    }

    /** @return list<Vehicle> */
    private function vehicles(array $spec): array
    {
        $rows = [
            ['Truk Engkel Mitsubishi Colt Diesel', '9123 UA', 'truk', 2021, 4000],
            ['Truk Double Hino Dutro', '9456 UB', 'truk', 2022, 6000],
            ['Pick Up Isuzu Traga', '8789 UC', 'pickup', 2023, 1000],
        ];

        return array_map(fn (array $row, int $i) => Vehicle::query()->where('code', sprintf('%s-KDR-%02d', $spec['prefix'], $i + 1))->first()
            ?? app(DeliveryMasterService::class)->createVehicle([
                'code' => sprintf('%s-KDR-%02d', $spec['prefix'], $i + 1), 'name' => $row[0], 'plate_number' => $spec['plate'].' '.$row[1],
                'type' => $row[2], 'year' => $row[3], 'fuel' => 'solar', 'capacity' => $row[4],
                'stnk_expires_on' => now()->addMonths(4 + $i * 5)->toDateString(), 'kir_expires_on' => now()->addMonths(2 + $i * 3)->toDateString(),
            ]), $rows, array_keys($rows));
    }

    /** @return list<Asset> */
    private function cylinders(TenantAssetCategory $category, array $spec): array
    {
        $out = [];
        for ($i = 1; $i <= self::CYLINDERS; $i++) {
            [$name, $gas, $grade, $color, $volume, $unit, $type] = self::PRODUCTS[($i - 1) % count(self::PRODUCTS)];
            $age = 6 + ($i * 7) % 90;
            $out[] = app(AssetService::class)->create([
                'name' => $name,
                'serial_number' => sprintf('%s-TBG-%04d', $spec['prefix'], $i),
                'category_id' => $category->id,
                // A couple are being repaired, so the list shows more than one status.
                'status' => $i % 20 === 0 ? AssetStatus::UNDER_MAINTENANCE : AssetStatus::AVAILABLE,
                'acquisition_date' => now()->subMonths($age)->toDateString(),
                'attributes' => $this->values($category, [
                    'Business Unit' => $spec['unit'], 'Warna' => $color, 'Bahan' => $type === 'Portabel' ? 'Aluminium' : 'Baja',
                    'Volume Tabung' => $volume, 'Satuan Volume' => $unit, 'Usia Tabung (Bulan)' => $age,
                    'Jenis Gas' => $gas, 'Grade Gas' => $grade, 'Jenis Tabung' => $type, 'UOM' => 'TBG',
                    'Qty' => 1, 'Catatan' => self::MARK,
                ]),
            ]);
        }

        return $out;
    }

    /**
     * Deliveries in every stage: done (outgoing and incoming), on the way, and planned.
     *
     * @param  list<Customer>  $customers
     * @param  list<Employee>  $drivers
     * @param  list<Employee>  $helpers
     * @param  list<Vehicle>  $vehicles
     * @param  list<Asset>  $cylinders
     */
    private function deliveries(array $spec, array $customers, array $drivers, array $helpers, array $vehicles, array $cylinders): void
    {
        $service = app(DeliveryService::class);
        [$lat, $lng] = $spec['base'];
        $at = fn (int $minutesAgo, float $north = 0, float $east = 0) => [
            'recorded_at' => now()->subMinutes($minutesAgo)->toIso8601String(), 'latitude' => $lat + $north, 'longitude' => $lng + $east, 'accuracy' => 12,
        ];
        $ids = fn (int $from, int $count) => array_map(fn (Asset $a) => $a->id, array_slice($cylinders, $from, $count));
        $plan = fn (string $direction, array $stops, int $vehicle) => $service->create([
            'direction' => $direction, 'planned_date' => now()->toDateString(), 'vehicle_id' => $vehicles[$vehicle]->id, 'notes' => self::MARK,
            'stops' => array_map(fn (array $s) => ['customer_id' => $s[0]->id, 'asset_ids' => $s[1]], $stops),
        ]);
        $crew = fn (Delivery $d, int $i, int $minutesAgo) => $service->setCrew($d, ['driver_id' => $drivers[$i]->id, 'helper_id' => $helpers[$i]->id, ...$at($minutesAgo)]);
        $scan = function (Delivery $d, string $phase, array $assetIds, array $meta, ?string $stop = null) use ($service) {
            foreach ($assetIds as $id) {
                $service->scan($d, ['phase' => $phase, 'code' => $id, 'stop_id' => $stop, ...$meta]);
            }
        };
        $report = fn (Delivery $d, string $type, array $meta, array $extra = []) => $service->report($d, ['type' => $type, ...$extra, ...$meta]);

        // 1. Outgoing, done: 8 cylinders to 2 customers.
        $done = $plan(Delivery::OUTGOING, [[$customers[0], $ids(0, 5)], [$customers[2], $ids(5, 3)]], 0);
        [$s1, $s2] = $done->stops()->get()->pluck('id')->all();
        $scan($done, 'load', $ids(0, 8), $at(600));
        $crew($done, 0, 590);
        $report($done, 'depart', $at(580));
        $report($done, 'position', $at(565, -0.010, 0.008));
        $report($done, 'arrive', $at(550, -0.021, 0.015), ['stop_id' => $s1]);
        $scan($done, 'unload', $ids(0, 5), $at(540, -0.021, 0.015), $s1);
        $report($done, 'stop_done', $at(530, -0.021, 0.015), ['stop_id' => $s1, 'receiver_name' => 'Ibu Sri']);
        $report($done, 'resume', $at(525, -0.021, 0.015));
        $report($done, 'arrive', $at(500, -0.042, 0.022), ['stop_id' => $s2]);
        $scan($done, 'unload', $ids(5, 3), $at(490, -0.042, 0.022), $s2);
        $report($done, 'stop_done', $at(480, -0.042, 0.022), ['stop_id' => $s2, 'receiver_name' => 'Pak Yudi']);
        $report($done, 'completed', $at(470, -0.042, 0.022));

        // 2. Incoming, done: 3 of them picked up again.
        $back = $plan(Delivery::INCOMING, [[$customers[0], $ids(0, 3)]], 0);
        $crew($back, 0, 400);
        $report($back, 'depart', $at(390));
        $scan($back, 'load', $ids(0, 3), $at(360, -0.021, 0.015));
        $report($back, 'stop_done', $at(350, -0.021, 0.015));
        $report($back, 'return', $at(320));
        $scan($back, 'unload', $ids(0, 3), $at(315));
        $report($back, 'completed', $at(310));

        // 3. Outgoing, on the way: first customer done, second on the way.
        $moving = $plan(Delivery::OUTGOING, [[$customers[1], $ids(8, 4)], [$customers[3], $ids(12, 4)]], 1);
        [$m1] = $moving->stops()->get()->pluck('id')->all();
        $scan($moving, 'load', $ids(8, 8), $at(120));
        $crew($moving, 1, 110);
        $report($moving, 'depart', $at(100));
        $report($moving, 'position', $at(85, 0.008, 0.010));
        $report($moving, 'arrive', $at(70, 0.016, 0.021), ['stop_id' => $m1]);
        $scan($moving, 'unload', $ids(8, 4), $at(60, 0.016, 0.021), $m1);
        $report($moving, 'stop_done', $at(50, 0.016, 0.021), ['stop_id' => $m1, 'receiver_name' => 'Pak Anton']);
        $report($moving, 'resume', $at(45, 0.016, 0.021));
        $report($moving, 'issue', $at(25, 0.024, 0.030), ['note' => 'Macet karena perbaikan jalan']);
        $report($moving, 'position', $at(8, 0.030, 0.036));

        // 4. Outgoing, planned: crew still to be entered on the phone.
        $plan(Delivery::OUTGOING, [[$customers[4], $ids(20, 5)]], 2);

        // 5. Incoming, planned: what is left at the first trip's second customer.
        $plan(Delivery::INCOMING, [[$customers[2], $ids(5, 3)]], 2);
    }

    /**
     * @param  array<string, mixed>  $byLabel
     * @return array<string, mixed> by field key
     */
    private function values(TenantAssetCategory $category, array $byLabel): array
    {
        $keys = app(CategoryAttributeService::class)->effective($category->id)->pluck('key', 'label');

        return collect($byLabel)->filter(fn ($v, $label) => $keys->has($label))->mapWithKeys(fn ($v, $label) => [$keys[$label] => $v])->all();
    }
}
