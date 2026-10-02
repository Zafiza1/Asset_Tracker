<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic "asset activity" timestamp: the last time any integration saw the
 * asset (RFID detection, GPS fix, ...). Additive and nullable — safe to roll
 * out on existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('metadata');
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
