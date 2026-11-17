<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Report fields for outgoing/incoming transactions (F-04): registers the new Field Registry
 * entries and `transaction.contact.view` (the outside party's contact is personal data,
 * APR-0006 D-10). The permission is in the transaction group, which every default role
 * template holds; custom roles are left untouched.
 */
return new class extends Migration
{
    private const CODES = ['transaction.contact.view'];

    private const ROLE_TEMPLATES = [RoleTemplates::SUPER_ADMIN, RoleTemplates::SUPERVISOR, RoleTemplates::ADMIN];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        FieldCatalog::sync();
        PermissionCatalog::sync();
        $permissions = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        $roles = DB::table('roles')->whereIn('template_code', self::ROLE_TEMPLATES)->get(['id', 'organization_id']);

        foreach ($roles as $role) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('report_field_registry')->where('data_source', 'asset_transaction')->whereIn('field_key', [
            'transaction.outgoing_nature', 'transaction.reason', 'transaction.counterparty_name', 'transaction.counterparty_org',
            'transaction.counterparty_contact', 'transaction.document_number', 'transaction.returned_on', 'transaction.return_status',
            'transaction.days_out', 'transaction.outgoing_number',
        ])->delete();
    }
};
