<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GPSController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectModuleController;
use App\Http\Controllers\RFIDController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\CustomFieldController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::middleware('throttle:auth')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
});

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Authentication routes
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:api');
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->middleware('throttle:api');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:api');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('throttle:api');
    Route::post('/auth/switch-organization', [AuthController::class, 'switchOrganization'])->middleware('throttle:api');
    Route::post('/auth/switch-project', [AuthController::class, 'switchProject'])->middleware('throttle:api');

    // API v1 Control Plane — organizations, projects, templates and memberships. These
    // name their tenant in the URL, so they run without the X-Organization-Id
    // / X-Project-Id context (see App\Middleware\ControlPlaneMiddleware);
    // access is decided by OrganizationPolicy / ProjectPolicy / TemplatePolicy.
    Route::prefix('v1')->middleware(['control-plane', 'throttle:api'])->group(function () {
        Route::apiResource('organizations', OrganizationController::class);
        Route::get('organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
        Route::post('organizations/{organization}/members', [OrganizationMemberController::class, 'store'])->middleware('throttle:api-write');
        Route::put('organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'destroy'])->middleware('throttle:api-write');

        Route::get('organizations/{organization}/projects', [ProjectController::class, 'index']);
        Route::post('organizations/{organization}/projects', [ProjectController::class, 'store'])->middleware('throttle:api-write');
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('throttle:api-write');
        Route::get('projects/{project}/members', [ProjectMemberController::class, 'index']);
        Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])->middleware('throttle:api-write');
        Route::put('projects/{project}/members/{user}', [ProjectMemberController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->middleware('throttle:api-write');

        // Template management (platform-level, not tenant-specific)
        Route::apiResource('templates', TemplateController::class);
        Route::post('templates/{template}/versions', [TemplateController::class, 'createVersion'])->middleware('throttle:api-write');
        Route::get('templates/{template}/versions', [TemplateController::class, 'versions']);
        Route::get('templates/{template}/versions/{version}', [TemplateController::class, 'showVersion']);
        Route::put('templates/{template}/current-version', [TemplateController::class, 'setCurrentVersion'])->middleware('throttle:api-write');
        Route::post('templates/{template}/versions/{version}/modules', [TemplateController::class, 'associateModule'])->middleware('throttle:api-write');
        Route::delete('templates/{template}/versions/{version}/modules', [TemplateController::class, 'dissociateModule'])->middleware('throttle:api-write');
        Route::post('templates/{template}/deprecate', [TemplateController::class, 'deprecate'])->middleware('throttle:api-write');
    });

    // API v1 routes — every business/tenant-owned resource is registered here.
    // The `tenant` middleware resolves & validates the organization/project
    // context for the request (see App\Middleware\TenantMiddleware) before any
    // controller runs, so the TenantScope global scope has context to filter on.
    Route::prefix('v1')->middleware(['tenant'])->group(function () {
        // Assets - read operations have standard limit, write operations have stricter limit
        Route::get('custom-fields', [CustomFieldController::class, 'index'])->middleware('throttle:api');
        Route::post('custom-fields', [CustomFieldController::class, 'store'])->middleware('throttle:api-write');
        Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->middleware('throttle:api-write');
        Route::get('assets', [AssetController::class, 'index'])->middleware('throttle:api');
        Route::get('assets/{asset}', [AssetController::class, 'show'])->middleware('throttle:api');
        Route::post('assets', [AssetController::class, 'store'])->middleware('throttle:api-write');
        Route::put('assets/{asset}', [AssetController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->middleware('throttle:api-write');
        Route::get('assets/{asset}/movements', [MovementController::class, 'index'])->middleware('throttle:api');
        Route::post('assets/{asset}/movements', [MovementController::class, 'store'])->middleware('throttle:api-write');

        // Locations
        Route::get('locations', [LocationController::class, 'index'])->middleware('throttle:api');
        Route::get('locations/{location}', [LocationController::class, 'show'])->middleware('throttle:api');
        Route::post('locations', [LocationController::class, 'store'])->middleware('throttle:api-write');
        Route::put('locations/{location}', [LocationController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('locations/{location}', [LocationController::class, 'destroy'])->middleware('throttle:api-write');

        // Module registry (platform catalog) and per-project module lifecycle.
        Route::get('modules', [ModuleController::class, 'index'])->middleware('throttle:api');
        Route::get('modules/{module}', [ModuleController::class, 'show'])->middleware('throttle:api');

        Route::get('project-modules', [ProjectModuleController::class, 'index'])->middleware('throttle:api');
        Route::get('project-modules/{module}', [ProjectModuleController::class, 'show'])->middleware('throttle:api');
        Route::post('project-modules', [ProjectModuleController::class, 'store'])->middleware('throttle:api-write');
        Route::put('project-modules/{module}/configuration', [ProjectModuleController::class, 'configure'])->middleware('throttle:api-write');
        Route::post('project-modules/{module}/enable', [ProjectModuleController::class, 'enable'])->middleware('throttle:api-write');
        Route::post('project-modules/{module}/disable', [ProjectModuleController::class, 'disable'])->middleware('throttle:api-write');
        Route::post('project-modules/{module}/upgrade', [ProjectModuleController::class, 'upgrade'])->middleware('throttle:api-write');
        Route::delete('project-modules/{module}', [ProjectModuleController::class, 'destroy'])->middleware('throttle:api-write');

        // Integration management
        Route::get('integrations', [IntegrationController::class, 'index'])->middleware('throttle:api');
        Route::get('integrations/{integration}', [IntegrationController::class, 'show'])->middleware('throttle:api');
        Route::post('integrations', [IntegrationController::class, 'store'])->middleware('throttle:api-write');
        Route::put('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('integrations/{integration}', [IntegrationController::class, 'destroy'])->middleware('throttle:api-write');
        Route::post('integrations/{integration}/connect', [IntegrationController::class, 'connect'])->middleware('throttle:api-write');
        Route::post('integrations/{integration}/disconnect', [IntegrationController::class, 'disconnect'])->middleware('throttle:api-write');
        Route::post('integrations/{integration}/test', [IntegrationController::class, 'testConnection'])->middleware('throttle:api-write');
        Route::get('integrations/{integration}/health', [IntegrationController::class, 'healthCheck'])->middleware('throttle:api');
        Route::get('integrations/available', [IntegrationController::class, 'getAvailableIntegrations'])->middleware('throttle:api');
        Route::post('integrations/validate-config', [IntegrationController::class, 'validateConfig'])->middleware('throttle:api-write');

        // RFID integration endpoints
        Route::prefix('integrations/{integration}/rfid')->middleware('throttle:integration')->group(function () {
            Route::post('register-tag', [RFIDController::class, 'registerTag']);
            Route::post('register-reader', [RFIDController::class, 'registerReader']);
            Route::post('bulk-register-tags', [RFIDController::class, 'bulkRegisterTags']);
            Route::post('ingest-read', [RFIDController::class, 'ingestTagRead']);
            Route::post('bulk-ingest-reads', [RFIDController::class, 'bulkIngestTagReads']);
            Route::get('devices', [RFIDController::class, 'getDevices']);
            Route::get('stats', [RFIDController::class, 'getStats']);
        });

        // GPS integration endpoints
        Route::prefix('integrations/{integration}/gps')->middleware('throttle:integration')->group(function () {
            Route::post('register-tracker', [GPSController::class, 'registerTracker']);
            Route::post('bulk-register-trackers', [GPSController::class, 'bulkRegisterTrackers']);
            Route::post('ingest-location', [GPSController::class, 'ingestLocationUpdate']);
            Route::post('bulk-ingest-locations', [GPSController::class, 'bulkIngestLocationUpdates']);
            Route::get('devices', [GPSController::class, 'getDevices']);
            Route::get('stats', [GPSController::class, 'getStats']);
            Route::get('asset-location', [GPSController::class, 'getAssetLocation']);
            Route::get('asset-location-history', [GPSController::class, 'getAssetLocationHistory']);
        });

        // Device management
        Route::get('devices', [DeviceController::class, 'index'])->middleware('throttle:api');
        Route::get('devices/{device}', [DeviceController::class, 'show'])->middleware('throttle:api');
        Route::post('devices', [DeviceController::class, 'store'])->middleware('throttle:api-write');
        Route::put('devices/{device}', [DeviceController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('throttle:api-write');
        Route::post('devices/{device}/bind', [DeviceController::class, 'bind'])->middleware('throttle:api-write');
        Route::post('devices/{device}/unbind', [DeviceController::class, 'unbind'])->middleware('throttle:api-write');
        Route::get('devices/{device}/bindings', [DeviceController::class, 'bindings'])->middleware('throttle:api');
        Route::put('devices/{device}/status', [DeviceController::class, 'updateStatus'])->middleware('throttle:api-write');

        // Device type management (platform-level)
        Route::get('device-types', [DeviceController::class, 'indexTypes'])->middleware('throttle:api');
        Route::get('device-types/{deviceType}', [DeviceController::class, 'showType'])->middleware('throttle:api');
        Route::post('device-types', [DeviceController::class, 'storeType'])->middleware('throttle:api-write');
        Route::put('device-types/{deviceType}', [DeviceController::class, 'updateType'])->middleware('throttle:api-write');
        Route::delete('device-types/{deviceType}', [DeviceController::class, 'destroyType'])->middleware('throttle:api-write');

        // Webhook management
        Route::get('webhooks', [WebhookController::class, 'index'])->middleware('throttle:api');
        Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:api-write');
        Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->middleware('throttle:api');
        Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('throttle:api-write');
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->middleware('throttle:api-write');
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->middleware('throttle:api');
        Route::get('webhooks/{webhook}/stats', [WebhookController::class, 'stats'])->middleware('throttle:api');
        Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:api-write');
        Route::post('webhooks/{webhook}/regenerate-secret', [WebhookController::class, 'regenerateSecret'])->middleware('throttle:api-write');
        Route::post('webhooks/{webhook}/retry-deliveries', [WebhookController::class, 'retryDeliveries'])->middleware('throttle:api-write');
        Route::post('webhooks/{webhook}/toggle-active', [WebhookController::class, 'toggleActive'])->middleware('throttle:api-write');

        // Audit logs
        Route::get('audit/activity-logs', [AuditController::class, 'indexActivityLogs'])->middleware('throttle:api');
        Route::get('audit/activity-logs/{id}', [AuditController::class, 'showActivityLog'])->middleware('throttle:api');
        Route::get('audit/security-logs', [AuditController::class, 'indexSecurityLogs'])->middleware('throttle:api');
        Route::get('audit/security-logs/{id}', [AuditController::class, 'showSecurityLog'])->middleware('throttle:api');
        Route::get('audit/event-logs', [AuditController::class, 'indexEventLogs'])->middleware('throttle:api');
        Route::get('audit/event-logs/{id}', [AuditController::class, 'showEventLog'])->middleware('throttle:api');
        Route::get('audit/stats', [AuditController::class, 'getStats'])->middleware('throttle:api');
    });
});
