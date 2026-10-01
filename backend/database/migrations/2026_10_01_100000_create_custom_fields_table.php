<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type')->default('asset');
            $table->string('key'); $table->string('label'); $table->string('type');
            $table->boolean('required')->default(false); $table->json('default_value')->nullable();
            $table->json('options')->nullable(); $table->json('validation')->nullable();
            $table->string('visibility')->default('visible'); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true); $table->timestamps(); $table->softDeletes();
            $table->unique(['project_id', 'entity_type', 'key']);
            $table->index(['organization_id', 'project_id', 'entity_type']);
        });
    }
    public function down(): void { Schema::dropIfExists('custom_fields'); }
};
