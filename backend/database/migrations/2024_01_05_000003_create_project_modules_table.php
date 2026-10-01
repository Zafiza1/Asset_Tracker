<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A module installed in a project (Section 14/15). Uninstalling keeps
        // the row with status "uninstalled" so the history stays traceable and
        // a later reinstall reuses it; hence one row per (project, module).
        Schema::create('project_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('restrict');
            $table->foreignId('module_version_id')->constrained()->onDelete('restrict');

            // installed, configured, enabled, disabled, uninstalled
            $table->string('status')->default('installed');
            $table->json('configuration')->nullable();

            $table->foreignId('installed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'module_id']);
            $table->index('organization_id');
            $table->index(['project_id', 'status']);
            $table->index('module_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
