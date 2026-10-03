<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module (App\Modules\Inventory). Stock itself is not stored: it is
 * the count of assets per location in Core. These tables hold the module's
 * own data — minimum levels and stock counts (cycle counts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            // null = all asset types at the location together
            $table->string('asset_type')->nullable();
            $table->unsignedInteger('min_quantity');
            $table->timestamps();

            $table->index(['project_id', 'location_id']);
            $table->index('organization_id');
        });

        // One rule per location and asset type ("all types" included).
        DB::statement("CREATE UNIQUE INDEX inventory_levels_location_type_unique ON inventory_levels (location_id, COALESCE(asset_type, ''))");

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            // open → completed | cancelled
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            // {expected, found, missing, unexpected, reconciled}
            $table->json('summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('location_id');
            $table->index('organization_id');
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->onDelete('cascade');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            // Recorded at the counted location when the count opened.
            $table->boolean('expected')->default(false);
            $table->boolean('scanned')->default(false);
            $table->timestamp('scanned_at')->nullable();
            // Where Core had a scanned-but-unexpected asset.
            $table->foreignId('recorded_location_id')->nullable()->constrained('locations')->onDelete('set null');
            // An unexpected asset moved to the counted location on completion.
            $table->boolean('reconciled')->default(false);
            $table->timestamps();

            $table->unique(['inventory_count_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory_levels');
    }
};
