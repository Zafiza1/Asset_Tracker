<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('license_codes', function (Blueprint $table) {
            $table->string('reference')->nullable()->after('notes');
        });

        // For existing license codes, try to extract reference from notes if it contains reference pattern
        \DB::statement("UPDATE license_codes SET reference = REGEXP_REPLACE(notes, '.*([A-Z]+-[0-9]{8}-[0-9]{3}).*', '\\1') WHERE notes ~ '[A-Z]+-[0-9]{8}-[0-9]{3}' AND reference IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('license_codes', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
