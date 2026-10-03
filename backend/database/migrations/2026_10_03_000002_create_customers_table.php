<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer module (App\Modules\Customer). Module-owned table: it references
 * a Core location (the customer's site) but never alters Core tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');

            // Customer-defined reference (e.g. their ERP code), unique per project.
            $table->string('code', 100);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            // The site assets are delivered to (a Core location of this project).
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index(['project_id', 'status']);
            $table->index('location_id');
        });

        // A deleted customer frees its code for reuse.
        DB::statement('CREATE UNIQUE INDEX customers_project_code_unique ON customers (project_id, code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
