<?php

/*
| Application modules ("containers"). Each tenant module has its own route file in
| routes/modules and is guarded by the `module:<name>` middleware. Listing a module in
| MODULES_DISABLED (comma-separated) puts only that module in maintenance: its endpoints
| answer 503 MODULE_UNAVAILABLE while every other module keeps working.
*/

return [
    'names' => [
        'assets' => 'Pendataan Aset',
        'transactions' => 'Transaksi Aset',
        'analytics' => 'Analisis Aset',
    ],

    'disabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('MODULES_DISABLED', ''))))),
];
