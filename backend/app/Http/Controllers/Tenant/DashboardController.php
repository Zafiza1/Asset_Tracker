<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Analytics\AssetAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Asset Analytics container: dashboard figures from live data. */
class DashboardController extends Controller
{
    public function show(AssetAnalytics $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->dashboard()]);
    }
}
