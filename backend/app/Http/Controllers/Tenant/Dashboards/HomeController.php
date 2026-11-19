<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Home\HomeCards;
use App\Domain\Dashboards\Home\HomePreferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home page ("Beranda", docs/factory/features/F-06 §3): the user's own work and notifications.
 * Open to every member; each card is included only with its permission (HomeCards::CARDS).
 * The member's card order and hidden cards come with the cards so the page needs one request.
 */
class HomeController extends Controller
{
    public function __construct(private readonly HomePreferences $preferences) {}

    public function cards(Request $request, HomeCards $cards): JsonResponse
    {
        return response()->json([
            'data' => $cards->forCurrentUser(),
            'meta' => [
                'refresh_seconds' => HomeCards::CACHE_SECONDS,
                'preferences' => $this->preferences->for($request->user()),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(HomePreferences::rules());

        return response()->json(['data' => $this->preferences->save($request->user(), $data)]);
    }
}
