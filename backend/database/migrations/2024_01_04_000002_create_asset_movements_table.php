<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');

            // Nullable: a movement can originate from "nowhere" (first placement)
            // or end at "nowhere" (asset removed from tracking), per Section 26.
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->onDelete('set null');

            // Who/what reported this movement: manual, gps, rfid, api, etc.
            // (Section 9/61 — Core only ever receives this as an opaque string,
            // never coupled to a specific integration's internals.)
            $table->string('source')->default('manual');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('project_id');
            $table->index('asset_id');
            $table->index('from_location_id');
            $table->index('to_location_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
    }
};
