<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('type');
            $table->string('provider')->nullable();
            $table->enum('status', ['disconnected', 'connected', 'degraded'])->default('disconnected');
            $table->json('metadata')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id']);
            $table->index('type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
