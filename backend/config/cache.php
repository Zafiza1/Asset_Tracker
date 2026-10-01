<?php

return [

    'default' => env('CACHE_DRIVER', 'redis'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'asset_tracker'),

];
