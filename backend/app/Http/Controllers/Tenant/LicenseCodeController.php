<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\LicenseCodeResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * License code management for the current organization.
 * Administrators can create, assign, release, and revoke license codes.
 */
class LicenseCodeController extends Controller
{
    public function __construct(
        private readonly LicenseCodeService $licenseCodes,
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

        return LicenseCodeResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    /**
     * Create a new license code.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $count = $data['count'] ?? 1;
        $notes = $data['notes'] ?? null;

        $codes = $count === 1
            ? [$this->licenseCodes->create($notes)]
            : $this->licenseCodes->createBatch($count, $notes);

        return response()->json([
            'data' => LicenseCodeResource::collection($codes),
            'meta' => ['created' => count($codes)],
        ], 201);
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
