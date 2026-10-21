<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\GenericCategoryController;
use App\Http\Controllers\Platform\GenericDefinitionController;
use App\Http\Controllers\Platform\GenericReferenceController;
use App\Http\Controllers\Platform\GenericVersionController;
use App\Http\Controllers\Platform\OrganizationController as PlatformOrganizationController;
use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\CategoryAttributeController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\GenericCatalogController;
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
|  - platform:  /api/platform/*  platform accounts only, platform permissions
|  - tenant:    everything else  active organization from the session (membership checked), tenant permissions
*/

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
        // One account, several organizations: switch the active one, or create another.
        Route::put('/organization', [AuthController::class, 'switchOrganization']);
        Route::post('/organizations', [AuthController::class, 'createOrganization'])->middleware('throttle:register');
    });
});

Route::prefix('platform')->middleware(['auth:sanctum', 'platform'])->group(function () {
    Route::get('/organizations', [PlatformOrganizationController::class, 'index'])->middleware('permission:platform.organization.view');
    Route::post('/organizations', [PlatformOrganizationController::class, 'store'])->middleware('permission:platform.organization.manage');
    Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show'])->middleware('permission:platform.organization.view');
    Route::patch('/organizations/{organization}', [PlatformOrganizationController::class, 'update'])->middleware('permission:platform.organization.manage');
    Route::post('/organizations/{organization}/suspend', [PlatformOrganizationController::class, 'suspend'])->middleware('permission:platform.organization.manage');
    Route::post('/organizations/{organization}/activate', [PlatformOrganizationController::class, 'activate'])->middleware('permission:platform.organization.manage');

    Route::get('/audit-logs', [PlatformAuditLogController::class, 'index'])->middleware('permission:platform.audit.view');

    // Generic Master: drafts are edited with master.generic.manage; release/deprecate need master.generic.release.
    Route::prefix('generic-masters')->group(function () {
        $view = 'permission:master.generic.view';
        $manage = 'permission:master.generic.manage';
        $release = 'permission:master.generic.release';

        Route::get('/categories', [GenericCategoryController::class, 'index'])->middleware($view);
        Route::post('/categories', [GenericCategoryController::class, 'store'])->middleware($manage);
        Route::patch('/categories/{genericCategory}', [GenericCategoryController::class, 'update'])->middleware($manage);
        Route::post('/categories/{genericCategory}/archive', [GenericCategoryController::class, 'archive'])->middleware($manage);
        Route::post('/categories/{genericCategory}/restore', [GenericCategoryController::class, 'restore'])->middleware($manage);

        Route::get('/definitions', [GenericDefinitionController::class, 'index'])->middleware($view);
        Route::post('/definitions', [GenericDefinitionController::class, 'store'])->middleware($manage);
        Route::get('/definitions/{genericDefinition}', [GenericDefinitionController::class, 'show'])->middleware($view);
        Route::patch('/definitions/{genericDefinition}', [GenericDefinitionController::class, 'update'])->middleware($manage);
        Route::post('/definitions/{genericDefinition}/archive', [GenericDefinitionController::class, 'archive'])->middleware($manage);
        Route::post('/definitions/{genericDefinition}/restore', [GenericDefinitionController::class, 'restore'])->middleware($manage);
        Route::post('/definitions/{genericDefinition}/versions', [GenericDefinitionController::class, 'createVersion'])->middleware($manage);

        Route::get('/versions/{genericVersion}', [GenericVersionController::class, 'show'])->middleware($view);
        Route::patch('/versions/{genericVersion}', [GenericVersionController::class, 'update'])->middleware($manage);
        Route::delete('/versions/{genericVersion}', [GenericVersionController::class, 'destroy'])->middleware($manage);
        Route::post('/versions/{genericVersion}/release', [GenericVersionController::class, 'release'])->middleware($release);
        Route::post('/versions/{genericVersion}/deprecate', [GenericVersionController::class, 'deprecate'])->middleware($release);
        Route::post('/versions/{genericVersion}/attributes', [GenericVersionController::class, 'storeAttribute'])->middleware($manage);
        Route::patch('/attributes/{genericAttribute}', [GenericVersionController::class, 'updateAttribute'])->middleware($manage);
        Route::delete('/attributes/{genericAttribute}', [GenericVersionController::class, 'destroyAttribute'])->middleware($manage);

        Route::get('/units', [GenericReferenceController::class, 'units'])->middleware($view);
        Route::post('/units', [GenericReferenceController::class, 'storeUnit'])->middleware($manage);
        Route::patch('/units/{unit}', [GenericReferenceController::class, 'updateUnit'])->middleware($manage);
        Route::get('/location-types', [GenericReferenceController::class, 'locationTypes'])->middleware($view);
        Route::post('/location-types', [GenericReferenceController::class, 'storeLocationType'])->middleware($manage);
        Route::patch('/location-types/{locationType}', [GenericReferenceController::class, 'updateLocationType'])->middleware($manage);
        Route::get('/transaction-types', [GenericReferenceController::class, 'transactionTypes'])->middleware($view);
        Route::patch('/transaction-types/{transactionType}', [GenericReferenceController::class, 'updateTransactionType'])->middleware($manage);
    });
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/organization', [OrganizationProfileController::class, 'show'])->middleware('permission:organization.view');
    Route::put('/organization', [OrganizationProfileController::class, 'update'])->middleware('permission:organization.manage');
    Route::get('/settings', [OrganizationProfileController::class, 'settings'])->middleware('permission:settings.manage');
    Route::put('/settings', [OrganizationProfileController::class, 'updateSettings'])->middleware('permission:settings.manage');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create,role.manage');
    Route::get('/users/organization-options', [UserController::class, 'organizationOptions'])->middleware('permission:user.create,role.manage');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::get('/users/{user}/organizations', [UserController::class, 'organizations'])->middleware('permission:user.view');
    Route::post('/users/{user}/organizations', [UserController::class, 'grantOrganization'])->middleware('permission:user.create,role.manage');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:user.update');
    Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:user.deactivate');
    Route::post('/users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:user.deactivate');
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:user.deactivate');
    Route::put('/users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:role.manage');
    Route::put('/users/{user}/scopes', [UserController::class, 'syncScopes'])->middleware('permission:user.update');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(['permission:user.update', 'throttle:sensitive']);

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
