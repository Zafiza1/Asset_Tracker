<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\LicenseCodeResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * License code management for the current organization.
 * Administrators can create, assign, release, and revoke license codes.
 */
class LicenseCodeController extends Controller
{
    public function __construct(
        private readonly LicenseCodeService $licenseCodes,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * List all license codes for the current organization.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(LicenseCode::STATUSES)],
        ]);

        $query = LicenseCode::query()
            ->with('assignedUser')
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                LicenseCode::STATUS_AVAILABLE => $q->available(),
                LicenseCode::STATUS_ASSIGNED => $q->assigned(),
                LicenseCode::STATUS_REVOKED => $q->revoked(),
            });

        ListQuery::sort($query, $request, ['code' => 'code', 'created_at' => 'created_at', 'assigned_at' => 'assigned_at'], '-created_at');

        $result = $query->paginate(ListQuery::perPage($request))->withQueryString();

        return LicenseCodeResource::collection($result);
    }

    /**
     * Debug endpoint to check license codes in database
     */
    public function debug(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Debug endpoint working']);
    }

    /**
     * Generate license codes for licenses that don't have them
     */
    public function generateMissing(Request $request): JsonResponse
    {
        $orgId = $this->tenancy->organizationId();

        $licenses = \App\Domain\Licensing\Models\OrganizationLicense::where('organization_id', $orgId)->get();
        $existingCodes = LicenseCode::where('organization_id', $orgId)->pluck('reference')->toArray();

        $created = [];
        foreach ($licenses as $license) {
            if ($license->reference && !in_array($license->reference, $existingCodes)) {
                try {
                    $code = LicenseCodeService::createForOrganization(
                        \App\Domain\Organization\Models\Organization::find($orgId),
                        $license->notes,
                        $license->reference
                    );
                    $created[] = [
                        'license_reference' => $license->reference,
                        'license_code' => $code->code,
                    ];
                } catch (\Exception $e) {
                    // Skip if fails
                }
            }
        }

        return response()->json([
            'total_licenses' => $licenses->count(),
            'existing_codes' => count($existingCodes),
            'created_codes' => count($created),
            'created' => $created,
        ]);
    }

    /**
     * Assign a license code to a user.
     */
    public function assign(Request $request, LicenseCode $licenseCode): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $licenseCode = $this->licenseCodes->assign($licenseCode->id, $data['user_id']);

        return response()->json(['data' => new LicenseCodeResource($licenseCode->load('assignedUser'))]);
    }

    /**
     * Release a license code (when user resigns).
     */
    public function release(LicenseCode $licenseCode): JsonResponse
    {
        $licenseCode = $this->licenseCodes->release($licenseCode->id);

        return response()->json(['data' => new LicenseCodeResource($licenseCode->load('assignedUser'))]);
    }

    /**
     * Revoke a license code permanently.
     */
    public function revoke(LicenseCode $licenseCode): JsonResponse
    {
        $licenseCode = $this->licenseCodes->revoke($licenseCode->id);

        return response()->json(['data' => new LicenseCodeResource($licenseCode->load('assignedUser'))]);
    }

    /**
     * Get a specific license code.
     */
    public function show(LicenseCode $licenseCode): JsonResponse
    {
        $licenseCode = $this->licenseCodes->getById($licenseCode->id);

        return response()->json(['data' => new LicenseCodeResource($licenseCode)]);
    }
}
