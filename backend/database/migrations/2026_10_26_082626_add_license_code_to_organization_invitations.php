<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add license_code_id to organization_invitations to track which license code
 * was assigned during invitation and used during registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->uuid('license_code_id')->nullable()->after('home_department_id');
        });

        // Composite FK: (organization_id, license_code_id) -> license_codes (organization_id, id)
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_license_code_id_tfk
            FOREIGN KEY (organization_id, license_code_id) REFERENCES license_codes (organization_id, id) ON DELETE RESTRICT");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE organization_invitations DROP CONSTRAINT organization_invitations_license_code_id_tfk');
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropColumn('license_code_id');
        });
    }
};
