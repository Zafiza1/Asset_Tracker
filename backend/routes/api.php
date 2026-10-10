<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\OrganizationController as PlatformOrganizationController;
use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Tenant\AuditLogController;
use App\Http\Controllers\Tenant\OrganizationProfileController;
use App\Http\Controllers\Tenant\RoleController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;

/*
| Route groups:
|  - auth:      session login/logout/profile (no tenant context; works while must_change_password)
|  - platform:  /api/platform/*  platform accounts only, platform permissions
|  - tenant:    everything else  organization derived from the account, tenant permissions
*/

Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
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
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/organization', [OrganizationProfileController::class, 'show'])->middleware('permission:organization.view');
    Route::put('/organization', [OrganizationProfileController::class, 'update'])->middleware('permission:organization.manage');
    Route::get('/settings', [OrganizationProfileController::class, 'settings'])->middleware('permission:settings.manage');
    Route::put('/settings', [OrganizationProfileController::class, 'updateSettings'])->middleware('permission:settings.manage');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create,role.manage');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
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
});
