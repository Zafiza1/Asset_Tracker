<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create platform admin
        $platformAdmin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7899',
            'status' => 'active',
        ]);
        $platformAdmin->assignRole('platform-admin');

        // Get organizations
        $orgSurya = Organization::where('slug', 'pt-surya-inti-gas')->first();
        $orgABC = Organization::where('slug', 'pt-abc-logistics')->first();
        $orgXYZ = Organization::where('slug', 'pt-xyz-manufacturing')->first();

        // Create users for PT Surya Inti Gas
        $userSuryaAdmin = User::create([
            'name' => 'Admin SIG',
            'email' => 'admin@suryaintigas.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7890',
            'status' => 'active',
        ]);

        $userSuryaManager = User::create([
            'name' => 'Manager SIG',
            'email' => 'manager@suryaintigas.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7891',
            'status' => 'active',
        ]);

        $userSuryaOperator = User::create([
            'name' => 'Operator SIG',
            'email' => 'operator@suryaintigas.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7892',
            'status' => 'active',
        ]);

        // Create users for PT ABC Logistics
        $userABCAdmin = User::create([
            'name' => 'Admin ABC',
            'email' => 'admin@abclogistics.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7893',
            'status' => 'active',
        ]);

        $userABCManager = User::create([
            'name' => 'Manager ABC',
            'email' => 'manager@abclogistics.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7894',
            'status' => 'active',
        ]);

        // Create users for PT XYZ Manufacturing
        $userXYZAdmin = User::create([
            'name' => 'Admin XYZ',
            'email' => 'admin@xyzmanufacturing.com',
            'password' => Hash::make('password'),
            'phone' => '+62-812-3456-7895',
            'status' => 'active',
        ]);

        // Attach users to organizations
        // PT Surya Inti Gas
        $orgSurya->users()->attach($userSuryaAdmin->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $orgSurya->users()->attach($userSuryaManager->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);
        $orgSurya->users()->attach($userSuryaOperator->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        // PT ABC Logistics
        $orgABC->users()->attach($userABCAdmin->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $orgABC->users()->attach($userABCManager->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        // PT XYZ Manufacturing
        $orgXYZ->users()->attach($userXYZAdmin->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        // Assign roles using new role system
        $userSuryaAdmin->assignRole('organization-owner', $orgSurya->id);
        $userSuryaManager->assignRole('manager', $orgSurya->id);
        $userSuryaOperator->assignRole('operator', $orgSurya->id);
        
        $userABCAdmin->assignRole('organization-owner', $orgABC->id);
        $userABCManager->assignRole('manager', $orgABC->id);
        
        $userXYZAdmin->assignRole('organization-owner', $orgXYZ->id);

        $this->command->info('Users seeded successfully.');
    }
}
