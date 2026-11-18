<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Home\HomeCards;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Home page ("Beranda", docs/factory/features/F-06 §3): the user's own work and notifications.
 * Open to every member; each card is included only with its permission (HomeCards::CARDS).
 */
class HomeController extends Controller
{
    public function cards(HomeCards $cards): JsonResponse
    {
        return response()->json([
            'data' => $cards->forCurrentUser(),
            'meta' => ['refresh_seconds' => HomeCards::CACHE_SECONDS],
        ]);
    }
}
