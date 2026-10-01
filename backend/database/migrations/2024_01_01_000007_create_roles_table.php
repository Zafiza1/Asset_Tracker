<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('level')->default(0); // Higher level = more privileges
            $table->boolean('is_system')->default(false); // System roles cannot be deleted
            $table->timestamps();

            $table->index('slug');
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
