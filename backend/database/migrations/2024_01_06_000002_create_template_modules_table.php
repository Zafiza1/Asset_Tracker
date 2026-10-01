<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_version_id')->constrained('template_versions')->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('version_constraint')->nullable(); // e.g., ">=1.0.0", "2.x"
            $table->boolean('required')->default(true);
            $table->json('default_config')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_version_id', 'module_id']);
            $table->index('template_version_id');
            $table->index('module_id');
            $table->index('required');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_modules');
    }
};
