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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('device_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('integration_id')->nullable()->constrained()->onDelete('set null');
            $table->string('system_id')->unique();
            $table->string('serial_number')->nullable();
            $table->string('name');
            $table->enum('status', ['offline', 'online', 'error'])->default('offline');
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id']);
            $table->index('system_id');
            $table->index('status');
            $table->index(['project_id', 'serial_number']);
            $table->index('integration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
