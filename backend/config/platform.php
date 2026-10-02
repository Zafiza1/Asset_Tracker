<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-service Organizations
    |--------------------------------------------------------------------------
    |
    | When true, any authenticated user can create an organization and becomes
    | its Organization Owner (Section 68: onboarding without platform staff).
    | When false, only platform admins and users granted the global
    | organization.create permission can.
    |
    */

    'self_service_organizations' => (bool) env('PLATFORM_SELF_SERVICE_ORGANIZATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Named limiters registered in AppServiceProvider and applied per route
    | group (throttle:auth|api|api-write|integration). Keyed by user id when
    | authenticated, otherwise by IP.
    |
    */

    'rate_limits' => [
        'auth' => (int) env('RATE_LIMIT_AUTH', 5),
        'api' => (int) env('RATE_LIMIT_API', 60),
        'api_write' => (int) env('RATE_LIMIT_API_WRITE', 30),
        'integration' => (int) env('RATE_LIMIT_INTEGRATION', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Ingestors
    |--------------------------------------------------------------------------
    |
    | Integration type => class implementing
    | App\Integrations\Contracts\IngestsReadings. Used by the generic
    | POST /api/v1/integrations/{integration}/ingest endpoint. Adding a new
    | technology (BLE, LoRaWAN, ...) means adding an entry here — Core is
    | untouched.
    |
    */

    'ingestors' => [
        'rfid' => App\Services\RFIDService::class,
        'gps' => App\Services\GPSService::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | An asset with bound devices counts as offline when it has not been seen
    | by any integration for this many minutes.
    |
    */

    'offline_after_minutes' => (int) env('PLATFORM_OFFLINE_AFTER_MINUTES', 60),

];
