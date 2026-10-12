<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches ("cabang") are not used by organizations: locations (with sub-locations) and
 * departments are enough. Removes the table and every reference to it, including the
 * "branch" data scope type (existing branch scopes are deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DELETE FROM user_data_scopes WHERE scope_type = 'branch'");
        DB::statement('ALTER TABLE user_data_scopes DROP CONSTRAINT user_data_scopes_ref_check');
        DB::statement('DROP INDEX user_data_scopes_unique');
        DB::statement('ALTER TABLE user_data_scopes DROP CONSTRAINT user_data_scopes_branch_id_tfk');
        Schema::table('user_data_scopes', fn (Blueprint $table) => $table->dropColumn('branch_id'));
        $this->scopeConstraints(withBranch: false);

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_home_branch_id_tfk');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('home_branch_id'));

        foreach (['departments', 'locations'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_branch_id_tfk");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch_id'));
        }

        Schema::drop('branches');
    }

    /** Restores the structure (not the data) of branches. */
    public function down(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('branches');
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('active','inactive','archived'))");
        TenantSchema::enableRls('branches');

        foreach (['departments', 'locations', 'users'] as $table) {
            $column = $table === 'users' ? 'home_branch_id' : 'branch_id';
            Schema::table($table, fn (Blueprint $t) => $t->uuid($column)->nullable());
            TenantSchema::tenantForeign($table, $column, 'branches');
        }

        DB::statement('ALTER TABLE user_data_scopes DROP CONSTRAINT user_data_scopes_ref_check');
        DB::statement('DROP INDEX user_data_scopes_unique');
        Schema::table('user_data_scopes', fn (Blueprint $table) => $table->uuid('branch_id')->nullable());
        TenantSchema::tenantForeign('user_data_scopes', 'branch_id', 'branches');
        $this->scopeConstraints(withBranch: true);
    }

    private function scopeConstraints(bool $withBranch): void
    {
        $noBranch = $withBranch ? ' AND branch_id IS NULL' : '';
        $branchRow = $withBranch ? "(scope_type = 'branch' AND branch_id IS NOT NULL AND department_id IS NULL AND location_id IS NULL) OR" : '';
        DB::statement("ALTER TABLE user_data_scopes ADD CONSTRAINT user_data_scopes_ref_check CHECK (
            (scope_type = 'organization'{$noBranch} AND department_id IS NULL AND location_id IS NULL) OR
            {$branchRow}
            (scope_type = 'department' AND department_id IS NOT NULL{$noBranch} AND location_id IS NULL) OR
            (scope_type = 'location' AND location_id IS NOT NULL{$noBranch} AND department_id IS NULL))");
        $refs = $withBranch ? 'branch_id, department_id, location_id' : 'department_id, location_id';
        DB::statement("CREATE UNIQUE INDEX user_data_scopes_unique ON user_data_scopes
            (user_id, scope_type, coalesce({$refs}, '00000000-0000-0000-0000-000000000000'::uuid))");
    }
};
