<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-owned generic master: kinds of locations (warehouse, office, site, ...).
        Schema::create('location_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

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

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('branch_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('departments');
        TenantSchema::tenantForeign('departments', 'branch_id', 'branches');

        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('branch_id');
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('location_type_id')->constrained('location_types');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('address')->nullable();
            // Materialized ancestry "/<root-id>/.../<id>/" used for sub-location scope queries.
            $table->text('path')->default('');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'path']);
        });
        TenantSchema::tenantKey('locations');
        TenantSchema::tenantForeign('locations', 'branch_id', 'branches');
        TenantSchema::tenantForeign('locations', 'parent_id', 'locations');

        foreach (['location_types', 'branches', 'departments', 'locations'] as $t) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$t}_status_check CHECK (status IN ('active','inactive','archived'))");
        }
        foreach (['branches', 'departments', 'locations'] as $t) {
            TenantSchema::enableRls($t);
        }

        TenantSchema::tenantForeign('users', 'home_branch_id', 'branches');
        TenantSchema::tenantForeign('users', 'home_department_id', 'departments');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_home_branch_id_tfk');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_home_department_id_tfk');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('location_types');
    }
};
