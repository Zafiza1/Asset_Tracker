<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Reverse proxies whose X-Forwarded-For header is believed, so request()->ip()
    | (and the audit trail) records the user's real address instead of the proxy's.
    | Loopback covers the Vite dev proxy and an Nginx on the same host; add the
    | address of any load balancer in front of the app. Never use '*' unless the
    | app is unreachable except through that proxy, or clients can spoof their IP.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
