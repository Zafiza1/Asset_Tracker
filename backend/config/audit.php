<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Activity logs (who changed what), security logs (authentication and
    | access-control events) and event logs (normalized integration/domain
    | events). See docs/architecture/audit.md.
    |
    */

    'enabled' => (bool) env('AUDIT_ENABLED', true),

    /*
    | Every audit record is also written as a structured JSON line to this log
    | channel (config/logging.php), for shipping to centralized logging. Set to
    | null to only write to the database.
    */
    'log_channel' => env('AUDIT_LOG_CHANNEL', 'audit'),

    /*
    | Retention in days, enforced by `php artisan model:prune` (scheduled
    | daily in routes/console.php). Null keeps records forever.
    */
    'retention_days' => [
        'activity' => env('AUDIT_RETENTION_ACTIVITY_DAYS', 365),
        'security' => env('AUDIT_RETENTION_SECURITY_DAYS', 365),
        'event' => env('AUDIT_RETENTION_EVENT_DAYS', 90),
    ],

    /*
    | Keys whose values are never stored in audit metadata/details/payloads.
    | Matched case-insensitively as substrings of the key, at any depth
    | (e.g. "api_key", "webhook_secret", "Authorization").
    */
    'redact_keys' => [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'credential',
        'private_key',
        'signature',
        'cookie',
    ],

    /*
    | A burst of throttled requests from one client is recorded once per
    | window, so an attacker cannot flood the security log.
    */
    'rate_limit_log_window_seconds' => 60,

];
