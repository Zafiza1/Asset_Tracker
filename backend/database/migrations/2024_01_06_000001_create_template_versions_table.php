<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->onDelete('cascade');
            $table->string('version');
            $table->text('description')->nullable();
            $table->json('default_modules')->nullable();
            $table->json('default_settings')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('available'); // available, deprecated
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version']);
            $table->index('template_id');
            $table->index('version');
            $table->index('status');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_versions');
    }
};
