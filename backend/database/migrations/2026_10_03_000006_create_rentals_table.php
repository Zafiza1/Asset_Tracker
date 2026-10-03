<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental module (App\Modules\Rental). Module-owned table: it references Core
 * assets/locations and Customer module rows, never alters Core tables.
 * Asset positions change only through Core's MovementService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');

            // Optional contract reference.
            $table->string('reference', 100)->nullable();
            // reserved → active → returned | cancelled
            $table->string('status', 20)->default('reserved');

            $table->timestamp('starts_at');
            $table->timestamp('due_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Where the asset goes on checkout (default: the customer's site).
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->foreignId('returned_to_location_id')->nullable()->constrained('locations')->onDelete('set null');

            // Amounts are computed for reporting/ERP; billing is out of scope.
            $table->decimal('daily_rate', 12, 2)->nullable();
            $table->decimal('late_fee_per_day', 12, 2)->nullable();
            $table->unsignedInteger('rented_days')->nullable();
            $table->unsignedInteger('days_late')->nullable();
            $table->decimal('rental_amount', 14, 2)->nullable();
            $table->decimal('late_fee', 14, 2)->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'due_at']);
            $table->index(['asset_id', 'status']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
