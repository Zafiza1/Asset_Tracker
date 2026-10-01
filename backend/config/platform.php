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

];
