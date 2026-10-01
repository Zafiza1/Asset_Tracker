<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level module registry (Control Plane, Section 13). Global,
        // not tenant-owned: a module is published once and installed per project
        // through project_modules.
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('business'); // core, tracking, business, reporting, integration
            $table->string('author')->nullable();

            // Core modules (asset, location, movement) are always available to
            // every project and cannot be installed, disabled or uninstalled.
            $table->boolean('is_core')->default(false);

            $table->string('status')->default('available'); // available, deprecated
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
