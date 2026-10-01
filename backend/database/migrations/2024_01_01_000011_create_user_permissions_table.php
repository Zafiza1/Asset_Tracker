<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            // Allow direct permission assignment at different levels
            // This provides more granular control beyond role-based permissions

            $table->unique(['user_id', 'permission_id', 'organization_id', 'project_id']);
            $table->index('user_id');
            $table->index('permission_id');
            $table->index('organization_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
