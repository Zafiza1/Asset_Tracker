<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: request correlation for activity/security logs, and the
 * project a security event happened in (e.g. a denied project access).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->after('user_agent');
            $table->index('request_id');
        });

        Schema::table('security_logs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('organization_id')
                ->constrained()->nullOnDelete();
            $table->string('request_id', 64)->nullable()->after('user_agent');
            $table->index(['project_id', 'occurred_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('security_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['project_id', 'occurred_at']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('request_id');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
