<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-09: who answers for an asset. `custodian_user_id` is a member of the same organization
 * and `department_id` one of its departments; both optional. Composite foreign keys keep
 * both inside the organization (members and departments are never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE assets ADD COLUMN custodian_user_id uuid, ADD COLUMN department_id uuid');
        TenantSchema::memberForeign('assets', 'custodian_user_id');
        TenantSchema::tenantForeign('assets', 'department_id', 'departments');
        DB::statement('CREATE INDEX assets_custodian_idx ON assets (organization_id, custodian_user_id) WHERE custodian_user_id IS NOT NULL');
        DB::statement('CREATE INDEX assets_department_idx ON assets (organization_id, department_id) WHERE department_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP COLUMN custodian_user_id, DROP COLUMN department_id');
    }
};
