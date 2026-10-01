<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            // Allow role assignment at different levels
            // - organization_id: Role at organization level
            // - project_id: Role at project level
            // - Both null: Global platform role (for platform admin)

            $table->unique(['user_id', 'role_id', 'organization_id', 'project_id']);
            $table->index('user_id');
            $table->index('role_id');
            $table->index('organization_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
