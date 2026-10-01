<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Asset Tracker PaaS',
        'version' => '1.0.0',
        'status' => 'running',
    ]);
});
