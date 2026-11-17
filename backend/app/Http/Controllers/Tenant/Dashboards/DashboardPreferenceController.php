<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Models\UserDashboardPreference;
use App\Domain\Dashboards\Services\DashboardService;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The member's own dashboard preferences in the active organization. */
class DashboardPreferenceController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user(), $this->service->preference($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(DashboardService::preferenceRules());

        return $this->respond($request->user(), $this->service->savePreference($request->user(), $data));
    }

    private function respond(User $user, ?UserDashboardPreference $preference): JsonResponse
    {
        return response()->json(['data' => [
            'default_dashboard_id' => $preference?->default_dashboard_id,
            'sidebar_preferences' => (object) ($preference?->sidebar_preferences ?? []),
            // What the member actually lands on (their choice may no longer be accessible).
            'effective_default_dashboard_id' => $this->service->defaultFor($user)->id,
        ]]);
    }
}
