<?php

namespace Database\Seeders;

use App\Domain\Assets\Services\AssetService;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\AssetCondition;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demonstration data for local/staging: two organizations, each with one user per role.
 * Refuses to run in production. Password comes from DEMO_PASSWORD, or is generated and printed.
 */
class DemoSeeder extends Seeder
{
    private const ORGANIZATIONS = [
        'MAJU' => ['name' => 'PT Maju Jaya', 'domain' => 'majujaya.test', 'site' => ['JKT', 'Jakarta'], 'department' => ['OPS', 'Operasional']],
        'SENTOSA' => ['name' => 'PT Sentosa Gas', 'domain' => 'sentosagas.test', 'site' => ['SBY', 'Surabaya'], 'department' => ['DIST', 'Distribusi']],
    ];

    private const USERS = [
        'ASSET_MANAGER' => ['manager', 'Asset Manager'],
        'OPERATOR' => ['operator', 'Operator'],
        'APPROVER' => ['approver', 'Approver'],
        'AUDITOR' => ['auditor', 'Auditor'],
        'VIEWER' => ['viewer', 'Viewer'],
    ];

    public function run(Tenancy $tenancy, OrganizationProvisioner $provisioner): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $password = env('DEMO_PASSWORD') ?: Str::password(16);

        $tenancy->runAsSystem(function () use ($provisioner, $password) {
            $this->call(DatabaseSeeder::class);

            foreach (self::ORGANIZATIONS as $code => $spec) {
                if (Organization::query()->where('code', $code)->exists()) {
                    $this->command?->info("Organization {$code} already exists, skipped.");

                    continue;
                }
                $this->seedOrganization($provisioner, $code, $spec, $password);
            }
        });

        $this->command?->warn("Demo password for all demo users: {$password}");
    }

    /** @param array{name: string, domain: string, site: array{0: string, 1: string}, department: array{0: string, 1: string}} $spec */
    private function seedOrganization(OrganizationProvisioner $provisioner, string $code, array $spec, string $password): void
    {
        $result = $provisioner->provision(
            ['code' => $code, 'name' => $spec['name']],
            ['name' => 'Admin '.$spec['name'], 'email' => "admin@{$spec['domain']}", 'password' => $password],
            adminMustChangePassword: false,
        );
        $orgId = $result['organization']->id;

        $department = new Department(['code' => $spec['department'][0], 'name' => $spec['department'][1]]);
        $department->forceFill(['organization_id' => $orgId])->save();
        $location = new Location([
            'code' => 'GDG-'.$spec['site'][0],
            'name' => 'Gudang '.$spec['site'][1],
            'location_type_id' => LocationType::query()->where('code', 'GUDANG')->value('id'),
        ]);
        $location->forceFill(['organization_id' => $orgId])->save();

        foreach (self::USERS as $roleCode => [$local, $title]) {
            $user = new User([
                'name' => "{$title} {$spec['name']}",
                'email' => "{$local}@{$spec['domain']}",
                'password' => $password,
                'job_title' => $title,
                'home_department_id' => $department->id,
            ]);
            $user->forceFill(['user_type' => User::TYPE_TENANT, 'organization_id' => $orgId, 'status' => User::STATUS_ACTIVE])->save();

            $role = Role::query()->where('organization_id', $orgId)->where('code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->id, ['organization_id' => $orgId]);
            UserDataScope::grant($orgId, $user->id, 'organization');
        }

        $this->seedAssets($result['organization'], $result['admin'], $spec['site'], $location);

        $this->command?->info("Organization {$code} seeded (admin@{$spec['domain']}).");
    }

    /**
     * Categories, a second location and a handful of assets with history spread over the last
     * months, created through the same services as the API so the dashboard has real data.
     *
     * @param  array{0: string, 1: string}  $site  code and name used for location codes/names
     */
    private function seedAssets(Organization $organization, User $admin, array $site, Location $warehouse): void
    {
        app(Tenancy::class)->runAsTenant(new TenantContext($organization, $admin), function () use ($site, $warehouse) {
            $categories = app(TenantCategoryService::class);
            $it = $categories->create(['code' => 'IT', 'name' => 'Perangkat IT']);
            $vehicles = $categories->create(['code' => 'KENDARAAN', 'name' => 'Kendaraan']);
            $furniture = $categories->create(['code' => 'FURNITUR', 'name' => 'Furnitur Kantor']);
            $office = app(OrganizationStructureService::class)->createLocation([
                'code' => 'KTR-'.$site[0], 'name' => 'Kantor '.$site[1],
                'location_type_id' => LocationType::query()->where('code', 'KANTOR')->value('id'),
            ]);
            $conditions = AssetCondition::query()->pluck('id', 'code');

            $assets = app(AssetService::class);
            $transactions = app(AssetTransactionService::class);
            $specs = [
                ['Laptop Dell Latitude 5440', $it, $office, 'in_use', 17500000, 9],
                ['Laptop Lenovo ThinkPad E14', $it, $office, 'available', 14200000, 7],
                ['Printer Epson L3250', $it, $office, 'available', 2900000, 5],
                ['Server Rak HPE ProLiant', $it, $warehouse, 'in_use', 98000000, 11],
                ['Mobil Operasional Avanza', $vehicles, $warehouse, 'available', 265000000, 10],
                ['Motor Honda Vario', $vehicles, $warehouse, 'under_maintenance', 24000000, 4],
                ['Meja Rapat 10 Orang', $furniture, $office, 'in_use', 8500000, 8],
                ['Kursi Ergonomis', $furniture, $office, 'available', 3200000, 2],
            ];
            $created = [];
            foreach ($specs as $i => [$name, $category, $location, $status, $cost, $monthsAgo]) {
                $date = now()->subMonths($monthsAgo)->toDateString();
                $created[] = $assets->create([
                    'name' => $name, 'serial_number' => sprintf('SN-%04d', $i + 1), 'category_id' => $category->id,
                    'location_id' => $location->id, 'condition_id' => $conditions['BAIK'] ?? null, 'status' => $status,
                    'acquisition_date' => $date, 'acquisition_cost' => $cost, 'registered_on' => $date,
                ]);
            }

            $transactions->create(['asset_id' => $created[4]->id, 'type' => 'transfer', 'to_location_id' => $office->id,
                'transaction_date' => now()->subMonths(3)->toDateString(), 'notes' => 'Dipakai tim kantor cabang.']);
            $transactions->create(['asset_id' => $created[1]->id, 'type' => 'loan', 'borrower_name' => 'Rina (Keuangan)',
                'transaction_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(5)->toDateString()]);
            $transactions->create(['asset_id' => $created[5]->id, 'type' => 'status_change', 'to_condition_id' => $conditions['RUSAK_BERAT'] ?? null,
                'transaction_date' => now()->subMonth()->toDateString(), 'notes' => 'Mesin mati, menunggu suku cadang.']);
            $transactions->create(['asset_id' => $created[2]->id, 'type' => 'note',
                'transaction_date' => now()->subDays(3)->toDateString(), 'notes' => 'Penggantian tinta rutin.']);
        });
    }
}
