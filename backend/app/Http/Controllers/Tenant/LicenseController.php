<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationLicenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function show(Request $request, Tenancy $tenancy, Licensing $licensing): JsonResponse
    {
        try {
            $orgId = $tenancy->organizationId();
            
            // Run entire license status calculation in system context to bypass tenant scope
            $result = $tenancy->runAsSystem(function () use ($orgId, $licensing, $request) {
                $licenses = OrganizationLicense::query()->where('organization_id', $orgId)
                    ->orderByDesc('expires_at')->orderByDesc('created_at')->get();
                $status = $licensing->status($orgId);

                return [
                    'active' => $status->active,
                    'over_limit' => $status->overLimit(),
                    'seats' => [
                        'capacity' => $status->capacity,
                        'used' => $status->used(),
                        'active_members' => $status->activeMembers,
                        'pending_invitations' => $status->pendingInvitations,
                        'available' => $status->available(),
                    ],
                    'licenses' => OrganizationLicenseResource::collection($licenses)->resolve($request),
                ];
            });

            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            \Log::error('License API error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
}
