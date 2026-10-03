<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection module (App\Modules\Inspection). Module-owned tables: they
 * reference Core assets, never alter Core tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');

            $table->string('name');
            $table->text('description')->nullable();
            // Only offered for assets of this type; null = any asset.
            $table->string('asset_type')->nullable();
            // [{key, label, type: pass_fail|number|text, required}]
            $table->json('items');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['project_id', 'active']);
            $table->index('organization_id');
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            $table->foreignId('checklist_id')->nullable()->constrained('inspection_checklists')->onDelete('set null');

            // scheduled → completed | cancelled
            $table->string('status', 20)->default('scheduled');
            // pass | fail, once completed
            $table->string('result', 10)->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('next_due_at')->nullable();

            // The checklist items as they were when the inspection was recorded,
            // so editing a checklist never rewrites history.
            $table->json('checklist_snapshot')->nullable();
            $table->json('answers')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('performed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'scheduled_at']);
            $table->index(['asset_id', 'performed_at']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('inspection_checklists');
    }
};
