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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->onDelete('cascade');
            $table->foreignId('event_log_id')->nullable()->constrained('event_logs')->onDelete('set null');
            $table->string('event_type');
            $table->json('payload');
            $table->enum('status', ['pending', 'delivered', 'failed', 'retrying'])->default('pending');
            $table->integer('attempt')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('response_status')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'status']);
            $table->index('event_type');
            $table->index('attempt_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
