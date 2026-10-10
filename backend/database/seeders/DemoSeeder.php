<?php

namespace Database\Seeders;

use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Tenancy\Tenancy;
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
        'MAJU' => ['name' => 'PT Maju Jaya', 'domain' => 'majujaya.test', 'branch' => ['JKT', 'Cabang Jakarta'], 'department' => ['OPS', 'Operasional']],
        'SENTOSA' => ['name' => 'PT Sentosa Gas', 'domain' => 'sentosagas.test', 'branch' => ['SBY', 'Cabang Surabaya'], 'department' => ['DIST', 'Distribusi']],
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

    /** @param array{name: string, domain: string, branch: array{0: string, 1: string}, department: array{0: string, 1: string}} $spec */
    private function seedOrganization(OrganizationProvisioner $provisioner, string $code, array $spec, string $password): void
    {
        $result = $provisioner->provision(
            ['code' => $code, 'name' => $spec['name']],
            ['name' => 'Admin '.$spec['name'], 'email' => "admin@{$spec['domain']}", 'password' => $password],
            adminMustChangePassword: false,
        );
        $orgId = $result['organization']->id;

        $branch = new Branch(['code' => $spec['branch'][0], 'name' => $spec['branch'][1]]);
        $branch->forceFill(['organization_id' => $orgId])->save();
        $department = new Department(['code' => $spec['department'][0], 'name' => $spec['department'][1], 'branch_id' => $branch->id]);
        $department->forceFill(['organization_id' => $orgId])->save();
        $location = new Location([
            'code' => 'GDG-'.$spec['branch'][0],
            'name' => 'Gudang '.$spec['branch'][1],
            'branch_id' => $branch->id,
            'location_type_id' => LocationType::query()->where('code', 'GUDANG')->value('id'),
        ]);
        $location->forceFill(['organization_id' => $orgId])->save();

        foreach (self::USERS as $roleCode => [$local, $title]) {
            $user = new User([
                'name' => "{$title} {$spec['name']}",
                'email' => "{$local}@{$spec['domain']}",
                'password' => $password,
                'job_title' => $title,
                'home_branch_id' => $branch->id,
                'home_department_id' => $department->id,
            ]);
            $user->forceFill(['user_type' => User::TYPE_TENANT, 'organization_id' => $orgId, 'status' => User::STATUS_ACTIVE])->save();

            $role = Role::query()->where('organization_id', $orgId)->where('code', $roleCode)->firstOrFail();
            $user->roles()->attach($role->id, ['organization_id' => $orgId]);
            UserDataScope::grant($orgId, $user->id, 'organization');
        }

        $this->command?->info("Organization {$code} seeded (admin@{$spec['domain']}).");
    }
}
