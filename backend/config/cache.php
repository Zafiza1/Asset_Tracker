<?php

return [

    'default' => env('CACHE_DRIVER', 'redis'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'asset_tracker'),

];
