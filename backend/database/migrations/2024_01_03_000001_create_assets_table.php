<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');

            // Two identities (see docs/architecture/overview.md, Section 6):
            // system_id is immutable and platform-generated; serial_number is
            // customer-defined and only unique within its project (Section 6/16).
            $table->string('system_id')->unique();
            $table->string('serial_number');

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('asset_type')->nullable();
            $table->string('status')->default('active');

            // Ad-hoc custom attributes for this asset. The full Custom Field
            // definition engine (types, validation, visibility) is Phase 14;
            // this is just the free-form JSON bucket Section 24 calls "metadata".
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'serial_number']);
            $table->index('organization_id');
            $table->index('project_id');
            $table->index('system_id');
            $table->index('serial_number');
            $table->index('asset_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
