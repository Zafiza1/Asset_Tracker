<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every release of a module is its own immutable row (Section 15).
        // Projects pin a specific version through project_modules, so publishing
        // a new version never changes behaviour for an existing project.
        Schema::create('module_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('version'); // semver, e.g. 1.0.0
            $table->text('changelog')->nullable();

            // {"asset": ">=1.0.0", "customer": "^1.0"} — lives on the version,
            // not the module, because requirements change between releases.
            $table->json('dependencies')->nullable();

            // Declarative settings the module accepts, used to validate
            // project_modules.configuration (see App\Services\ModuleService).
            $table->json('config_schema')->nullable();

            // Permission slugs this version introduces (e.g. maintenance.view).
            $table->json('permissions')->nullable();

            // published: installable; deprecated: kept running for projects
            // already on it, but not offered for new installs/upgrades.
            $table->string('status')->default('published');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'version']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_versions');
    }
};
