<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\HealthController;
// Platform controllers removed - platform admin system deprecated
// use App\Http\Controllers\Platform\GenericCategoryController;
// use App\Http\Controllers\Platform\GenericDefinitionController;
// use App\Http\Controllers\Platform\GenericReferenceController;
// use App\Http\Controllers\Platform\GenericVersionController;
// use App\Http\Controllers\Platform\LicenseController as PlatformLicenseController;
// use App\Http\Controllers\Platform\OrganizationController as PlatformOrganizationController;
// use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\CategoryAttributeController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\GenericCatalogController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\LicenseCodeController;
use App\Http\Controllers\Tenant\LicenseController;
use App\Http\Controllers\Tenant\LicensePurchaseController;
use App\Http\Controllers\Tenant\LocationController;
use App\Http\Controllers\Tenant\LocationTypeController;
use App\Http\Controllers\Tenant\OrganizationProfileController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\TenantCategoryController;
use App\Http\Controllers\Tenant\TenantDefinitionController;
use App\Http\Controllers\Tenant\TenantVersionController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;

/*
| Route groups:
|  - auth:      session login/logout/profile (no tenant context; works while must_change_password)
|  - tenant:    everything else  active organization from the session (membership checked), tenant permissions
*/

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    // Invitation token in the body: describe the invitation, or sign up an address without an account.
    Route::post('/invitation/inspect', [InvitationAcceptanceController::class, 'inspect'])->middleware('throttle:invitation');
    Route::post('/invitation/register', [InvitationAcceptanceController::class, 'register'])->middleware('throttle:invitation');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
        // One account, several organizations: switch the active one, or create another.
        Route::put('/organization', [AuthController::class, 'switchOrganization']);
        Route::post('/organizations', [AuthController::class, 'createOrganization'])->middleware('throttle:register');
        // Joining an organization: the owner of the invited, verified address accepts.
        Route::post('/invitation/accept', [InvitationAcceptanceController::class, 'accept'])->middleware('throttle:invitation');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:verification');
        // Signed query (id, hash, expires, signature) from the verification email; checked in the controller.
        Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:sensitive')->name('verification.verify');
    });
});

// Platform routes removed - platform admin system deprecated

Route::middleware(['auth:sanctum', 'tenant', 'license'])->group(function () {
    // Seats and expiry of the organization's license (read-only banner, seat usage).
    Route::get('/license', [LicenseController::class, 'show'])->middleware('permission:organization.view');

    Route::get('/organization', [OrganizationProfileController::class, 'show'])->middleware('permission:organization.view');
    Route::put('/organization', [OrganizationProfileController::class, 'update'])->middleware('permission:organization.manage');
    // Settings are system defaults: readable, not editable by organizations.
    Route::get('/settings', [OrganizationProfileController::class, 'settings'])->middleware('permission:settings.manage');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
    Route::get('/users/organization-options', [UserController::class, 'organizationOptions'])->middleware('permission:user.create,role.manage');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::get('/users/{user}/organizations', [UserController::class, 'organizations'])->middleware('permission:user.view');
    Route::post('/users/{user}/organizations', [UserController::class, 'grantOrganization'])->middleware('permission:user.create,role.manage');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:user.update');
    Route::post('/users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:user.deactivate');
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:user.deactivate')->withoutMiddleware('license');
    Route::put('/users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:role.manage');
    Route::put('/users/{user}/scopes', [UserController::class, 'syncScopes'])->middleware('permission:user.update');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(['permission:user.update', 'throttle:sensitive']);

    // Adding users = inviting them; access starts when the owner of the address accepts.
    Route::get('/invitations', [InvitationController::class, 'index'])->middleware('permission:user.view');
    Route::post('/invitations', [InvitationController::class, 'store'])->middleware('permission:user.create,role.manage');
    Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware('permission:user.create,role.manage');
    Route::post('/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->middleware('permission:user.create,role.manage')->withoutMiddleware('license');

    // License codes: reusable activation codes for user accounts (automatically created on license purchase)
    Route::get('/license-codes', [LicenseCodeController::class, 'index'])->middleware('permission:license.manage');
    Route::get('/license-codes/debug', [LicenseCodeController::class, 'debug'])->middleware('permission:license.manage');
    Route::post('/license-codes/generate-missing', [LicenseCodeController::class, 'generateMissing'])->middleware('permission:license.manage');
    Route::get('/license-codes/{licenseCode}', [LicenseCodeController::class, 'show'])->middleware('permission:license.manage');
    Route::post('/license-codes/{licenseCode}/assign', [LicenseCodeController::class, 'assign'])->middleware('permission:license.manage');
    Route::post('/license-codes/{licenseCode}/release', [LicenseCodeController::class, 'release'])->middleware('permission:license.manage');
    Route::post('/license-codes/{licenseCode}/revoke', [LicenseCodeController::class, 'revoke'])->middleware('permission:license.manage');

    // License purchase: Super Admin can buy additional user licenses (payment bypassed)
    Route::post('/licenses/purchase', [LicensePurchaseController::class, 'purchase'])->middleware('permission:organization.manage');
    Route::get('/licenses/history', [LicensePurchaseController::class, 'history'])->middleware('permission:organization.view');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:role.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:role.manage');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:role.view');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.manage');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:role.manage');
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:role.view');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

    // Read-only Generic Master catalog, shared by all organizations (drafts are never exposed).
    Route::prefix('generic-masters')->middleware('permission:catalog.generic.view')->group(function () {
        Route::get('/categories', [GenericCatalogController::class, 'categories']);
        Route::get('/definitions', [GenericCatalogController::class, 'definitions']);
        Route::get('/definitions/{genericDefinition}', [GenericCatalogController::class, 'definition']);
        Route::get('/versions/{genericVersion}', [GenericCatalogController::class, 'version']);
        Route::get('/units', [GenericCatalogController::class, 'units']);
        Route::get('/location-types', [GenericCatalogController::class, 'locationTypes']);
        Route::get('/transaction-types', [GenericCatalogController::class, 'transactionTypes']);
    });

    // Organization structure and Tenant Custom Master: same CRUD + archive/restore shape.
    $view = 'permission:master.tenant.view';
    $create = 'permission:master.tenant.create';
    $update = 'permission:master.tenant.update';
    $archive = 'permission:master.tenant.archive';
    $masters = [
        'departments' => [DepartmentController::class, 'department'],
        'locations' => [LocationController::class, 'location'],
        'location-types' => [LocationTypeController::class, 'locationType'],
        'tenant-masters/categories' => [TenantCategoryController::class, 'tenantCategory'],
        'asset-definitions' => [TenantDefinitionController::class, 'tenantDefinition'],
    ];
    foreach ($masters as $uri => [$controller, $param]) {
        Route::get("/{$uri}", [$controller, 'index'])->middleware($view);
        Route::post("/{$uri}", [$controller, 'store'])->middleware($create);
        Route::get("/{$uri}/{{$param}}", [$controller, 'show'])->middleware($view);
        Route::patch("/{$uri}/{{$param}}", [$controller, 'update'])->middleware($update);
        Route::post("/{$uri}/{{$param}}/archive", [$controller, 'archive'])->middleware($archive);
        Route::post("/{$uri}/{{$param}}/restore", [$controller, 'restore'])->middleware($archive);
        Route::delete("/{$uri}/{{$param}}", [$controller, 'destroy'])->middleware($archive);
    }

    // Custom fields of asset categories; subcategories inherit their ancestors' fields.
    Route::get('/tenant-masters/categories/{tenantCategory}/attributes', [CategoryAttributeController::class, 'index'])->middleware($view);
    Route::post('/tenant-masters/categories/{tenantCategory}/attributes', [CategoryAttributeController::class, 'store'])->middleware($update);
    Route::put('/tenant-masters/categories/{tenantCategory}/attributes', [CategoryAttributeController::class, 'sync'])->middleware($update);
    Route::patch('/category-attributes/{categoryAttribute}', [CategoryAttributeController::class, 'update'])->middleware($update);
    Route::delete('/category-attributes/{categoryAttribute}', [CategoryAttributeController::class, 'destroy'])->middleware($update);

    Route::post('/asset-definitions/{tenantDefinition}/versions', [TenantDefinitionController::class, 'createVersion'])->middleware($update);
    Route::get('/asset-definition-versions/{tenantVersion}', [TenantVersionController::class, 'show'])->middleware($view);
    Route::patch('/asset-definition-versions/{tenantVersion}', [TenantVersionController::class, 'update'])->middleware($update);
    Route::delete('/asset-definition-versions/{tenantVersion}', [TenantVersionController::class, 'destroy'])->middleware($update);
    Route::get('/asset-definition-versions/{tenantVersion}/effective-schema', [TenantVersionController::class, 'effectiveSchema'])->middleware($view);
    Route::post('/asset-definition-versions/{tenantVersion}/release', [TenantVersionController::class, 'release'])->middleware($update);
    Route::post('/asset-definition-versions/{tenantVersion}/deprecate', [TenantVersionController::class, 'deprecate'])->middleware($update);
    Route::post('/asset-definition-versions/{tenantVersion}/attributes', [TenantVersionController::class, 'storeAttribute'])->middleware($update);
    Route::put('/asset-definition-versions/{tenantVersion}/overrides', [TenantVersionController::class, 'syncOverrides'])->middleware($update);
    Route::patch('/asset-definition-attributes/{tenantAttribute}', [TenantVersionController::class, 'updateAttribute'])->middleware($update);
    Route::delete('/asset-definition-attributes/{tenantAttribute}', [TenantVersionController::class, 'destroyAttribute'])->middleware($update);

    // Operational modules, one route file each; see config/modules.php for maintenance switches.
    require __DIR__.'/modules/assets.php';
    require __DIR__.'/modules/transactions.php';
    require __DIR__.'/modules/analytics.php';
});
