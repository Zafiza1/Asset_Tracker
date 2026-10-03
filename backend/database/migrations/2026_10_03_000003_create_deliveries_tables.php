<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery module (App\Modules\Delivery). Module-owned tables: they reference
 * Core assets/locations and Customer module rows, never alter Core tables.
 * Asset positions change only through Core's MovementService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');

            // Optional customer/ERP reference (delivery note number…).
            $table->string('reference', 100)->nullable();
            // pending → in_transit → delivered → returned | cancelled
            $table->string('status', 20)->default('pending');
            // Where the assets go; defaults to the customer's site.
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->onDelete('set null');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['project_id', 'status']);
            $table->index('customer_id');
            $table->index(['project_id', 'reference']);
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->onDelete('cascade');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            // pending → delivered → returned
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_to_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->timestamps();

            $table->unique(['delivery_id', 'asset_id']);
            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
    }
};
