<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles are now only Truk or Pick Up, and whether one is in use follows from the transactions, so
 * nobody switches vehicles off any more. A vehicle registered as "mobil" whose name says it is a pick
 * up becomes one; other old kinds stay as they are until the vehicle is next edited. Vehicles switched
 * off before are active again, so they can still be chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        DB::table('vehicles')->where('type', 'mobil')->whereRaw("name ILIKE '%pick%up%'")
            ->update(['type' => 'pickup', 'updated_at' => now()]);
        DB::table('vehicles')->where('status', 'inactive')->update(['status' => 'active', 'updated_at' => now()]);
    }

    /** Data only: which vehicles were "mobil" or switched off is not kept. */
    public function down(): void {}
};
