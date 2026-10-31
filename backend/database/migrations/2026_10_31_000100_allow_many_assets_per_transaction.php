<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One transaction can cover many assets: every asset gets its own history row, and all rows
 * recorded together share the transaction number. A number stays unique per asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'transaction_number']);
            $table->unique(['organization_id', 'transaction_number', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'transaction_number', 'asset_id']);
            $table->unique(['organization_id', 'transaction_number']);
        });
    }
};
