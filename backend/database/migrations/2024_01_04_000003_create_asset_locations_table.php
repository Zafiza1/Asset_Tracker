<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * asset_locations holds the CURRENT location per asset — a denormalized
     * pointer kept in sync by MovementService whenever an asset_movements row
     * is written, so "where is asset X right now" never has to scan history
     * (Section 23/25). Full history lives in asset_movements.
     */
    public function up(): void
    {
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('asset_id')->unique()->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');

            $table->string('source')->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamp('arrived_at')->nullable();

            $table->timestamps();

            $table->index('organization_id');
            $table->index('project_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_locations');
    }
};
