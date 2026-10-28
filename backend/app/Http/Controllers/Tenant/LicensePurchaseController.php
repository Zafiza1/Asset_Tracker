<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\AuditLogger;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationLicenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * License purchase controller for tenant organizations.
 * Super Admin can purchase additional user licenses (bypass payment for now).
 */
class LicensePurchaseController extends Controller
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Licensing $licensing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Purchase additional user licenses (per user).
     * Payment is bypassed for now - just confirm the purchase.
     */
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $quantity = $data['quantity'];
        $notes = $data['notes'] ?? null;

        $orgId = $this->tenancy->organizationId();

        // Run in system context to bypass tenant scope
        $result = $this->tenancy->runAsSystem(function () use ($orgId, $quantity, $notes) {
            $start = now();
            $end = $start->copy()->addYears(1); // 1 year license by default
            $organization = \App\Domain\Organization\Models\Organization::find($orgId);

            $licenses = [];
            for ($i = 0; $i < $quantity; $i++) {
                $reference = 'PURCHASE-' . date('Ymd') . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
                $license = $this->licensing->grant(
                    organization: $organization,
                    kind: OrganizationLicense::KIND_BASE,
                    seats: 1,
                    startsAt: $start,
                    expiresAt: $end,
                    reference: $reference,
                    notes: $notes,
                    source: OrganizationLicense::SOURCE_PURCHASE,
                );
                $licenses[] = $license;

                // Create license code for each purchased license with reference
                \App\Domain\Licensing\Services\LicenseCodeService::createForOrganization(
                    $organization,
                    $notes ?? 'Lisensi dari pembelian',
                    $reference
                );
            }

            return $licenses;
        });

        return response()->json([
            'data' => OrganizationLicenseResource::collection($result)->resolve($request),
            'meta' => [
                'purchased' => count($result),
                'message' => 'Berhasil membeli ' . count($result) . ' lisensi pengguna. Kode lisensi telah dibuat otomatis.',
            ],
        ], 201);
    }

    /**
     * Get purchase history for the organization.
     */
    public function history(Request $request): JsonResponse
    {
        $orgId = $this->tenancy->organizationId();

        $licenses = $this->tenancy->runAsSystem(function () use ($orgId) {
            return OrganizationLicense::query()
                ->where('organization_id', $orgId)
                ->where('source', OrganizationLicense::SOURCE_PURCHASE)
                ->orderByDesc('created_at')
                ->get();
        });

        return response()->json([
            'data' => OrganizationLicenseResource::collection($licenses)->resolve($request),
        ]);
    }
}
