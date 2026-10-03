<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance module (App\Modules\Maintenance). Module-owned table: it only
 * references Core assets, it never alters Core tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');

            $table->string('title');
            $table->text('description')->nullable();
            // Free-form per project (preventive, corrective, calibration…).
            $table->string('type', 50)->default('preventive');
            // scheduled → in_progress → completed | cancelled
            $table->string('status', 20)->default('scheduled');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->json('metadata')->nullable();

            // The record this one was auto-scheduled from (auto_schedule setting).
            $table->foreignId('previous_record_id')->nullable()->constrained('maintenance_records')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'scheduled_at']);
            $table->index('asset_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
