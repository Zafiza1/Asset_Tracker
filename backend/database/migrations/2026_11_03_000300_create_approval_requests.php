<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval of transactions, asset changes and master data changes (docs/08 §8). A change by a
 * user without approval.manage is stored here as a pending request (the original API call:
 * method, path, input, uploaded files) and only applied when a Super Admin or Supervisor
 * approves it. Also adds approval.manage to the existing SUPER_ADMIN and SUPERVISOR roles.
 */
return new class extends Migration
{
    private const PERMISSION = 'approval.manage';

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('category', 20);
            $table->string('action', 60);
            $table->string('summary', 255);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->jsonb('payload');
            $table->jsonb('files')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'requested_by', 'created_at']);
        });
        TenantSchema::tenantKey('approval_requests');
        TenantSchema::enableRls('approval_requests');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check
            CHECK (status IN ('pending','approved','rejected','cancelled'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_category_check
            CHECK (category IN ('transaction','asset','master'))");

        $app = config('database.app_role');
        DB::statement("GRANT SELECT, INSERT, UPDATE ON approval_requests TO {$app}");

        PermissionCatalog::sync();
        $permissionId = DB::table('permissions')->where('code', self::PERMISSION)->value('id');
        $roles = DB::table('roles')->whereIn('template_code', ['SUPER_ADMIN', 'SUPERVISOR'])->get(['id', 'organization_id']);
        foreach ($roles as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'organization_id' => $role->organization_id,
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::dropIfExists('approval_requests');
        $permissionId = DB::table('permissions')->where('code', self::PERMISSION)->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
