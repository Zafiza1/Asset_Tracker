<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * License codes are never revoked: a code only moves between "available" and "assigned"
 * (released from a user). Codes revoked earlier become available again, and the status check
 * no longer allows "revoked".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('license_codes')->where('status', 'revoked')->update([
            'status' => 'available',
            'assigned_to_user_id' => null,
            'assigned_at' => null,
            'updated_at' => now(),
        ]);

        DB::statement('ALTER TABLE license_codes DROP CONSTRAINT license_codes_status_check');
        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_status_check CHECK (status IN ('available', 'assigned'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE license_codes DROP CONSTRAINT license_codes_status_check');
        DB::statement("ALTER TABLE license_codes ADD CONSTRAINT license_codes_status_check CHECK (status IN ('available', 'assigned', 'revoked'))");
    }
};
