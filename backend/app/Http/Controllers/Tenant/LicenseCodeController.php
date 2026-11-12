<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\LicenseCodeService;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\LicenseCodeResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * License code management for the current organization.
 * Codes are created by license purchases; administrators assign them and release them from
 * users. A code is never revoked: it always stays reusable.
 */
class LicenseCodeController extends Controller
{
    public function __construct(
        private readonly LicenseCodeService $licenseCodes,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * List the organization's license codes. `search` matches the code, the license reference
     * and the assigned user's name or email; `counts` gives the number of codes per status
     * (ignoring the status filter, for the status tabs). Each code carries the license it was
     * bought with, for its validity period.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(LicenseCode::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) $request->query('search', ''));
        $base = LicenseCode::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', ListQuery::like($search))
                ->orWhere('reference', 'ilike', ListQuery::like($search))
                ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'ilike', ListQuery::like($search))->orWhere('email', 'ilike', ListQuery::like($search)))));

        $counts = array_fill_keys(LicenseCode::STATUSES, 0);
        foreach ((clone $base)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $counts[$status] = (int) $total;
        }

        $query = $base->with('assignedUser')
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                LicenseCode::STATUS_AVAILABLE => $q->available(),
                LicenseCode::STATUS_ASSIGNED => $q->assigned(),
            });

        ListQuery::sort($query, $request, ['code' => 'code', 'created_at' => 'created_at', 'assigned_at' => 'assigned_at'], '-created_at');

        $result = $query->paginate(ListQuery::perPage($request))->withQueryString();
        $this->attachLicenses($result->getCollection());

        return LicenseCodeResource::collection($result)->additional(['counts' => $counts]);
    }

    /**
     * Generate license codes for licenses that don't have them
     */
    public function generateMissing(Request $request): JsonResponse
    {
        $orgId = $this->tenancy->organizationId();

        $licenses = \App\Domain\Licensing\Models\OrganizationLicense::where('organization_id', $orgId)->get();
        $existingCodes = LicenseCode::where('organization_id', $orgId)->pluck('reference')->toArray();

        $organization = \App\Domain\Organization\Models\Organization::findOrFail($orgId);

        $created = [];
        $failed = [];
        foreach ($licenses as $license) {
            if ($license->reference && !in_array($license->reference, $existingCodes)) {
                // createForOrganization runs in its own (nested) transaction, so one failure
                // rolls back only that code; the rest are still generated and the failure is reported.
                try {
                    $code = LicenseCodeService::createForOrganization($organization, $license->notes, $license->reference);
                    $created[] = [
                        'license_reference' => $license->reference,
                        'license_code' => $code->code,
                    ];
                } catch (\Throwable $e) {
                    Log::error('Failed to generate missing license code', [
                        'exception' => $e,
                        'reference' => $license->reference,
                        'organization_id' => $orgId,
                        'request_id' => $request->attributes->get('request_id'),
                    ]);
                    $failed[] = $license->reference;
                }
            }
        }

        return response()->json([
            'total_licenses' => $licenses->count(),
            'existing_codes' => count($existingCodes),
            'created_codes' => count($created),
            'created' => $created,
            'failed' => $failed,
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
     * One license code with the license it belongs to and its history (assignments and
     * releases), newest first.
     */
    public function show(LicenseCode $licenseCode): JsonResponse
    {
        $licenseCode = $this->licenseCodes->getById($licenseCode->id);
        $this->attachLicenses(collect([$licenseCode]));

        $history = AuditLog::query()
            ->with('actor')
            ->where('entity_type', class_basename(LicenseCode::class))
            ->where('entity_id', $licenseCode->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id') // ordered UUIDs: same-instant entries stay in sequence
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'user_name' => $log->after['user_name'] ?? $log->before['user_name'] ?? null,
                'reason' => $log->metadata['reason'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [...(new LicenseCodeResource($licenseCode))->resolve(), 'history' => $history]]);
    }

    /**
     * Sets each code's `license` relation to the organization license with the same reference
     * (licenses are only readable in system context, see LicenseController), and
     * `pendingInvitation` to the open invitation that will give the code to its invitee.
     *
     * @param  Collection<int, LicenseCode>  $codes
     */
    private function attachLicenses(Collection $codes): void
    {
        $orgId = $this->tenancy->organizationId();
        $references = $codes->pluck('reference')->filter()->unique()->values()->all();
        $licenses = $references === [] ? collect() : $this->tenancy->runAsSystem(fn () => OrganizationLicense::query()
            ->where('organization_id', $orgId)->whereIn('reference', $references)->get()->keyBy('reference'));

        $available = $codes->filter(fn (LicenseCode $c) => $c->isAvailable())->pluck('id')->all();
        $invitations = $available === [] ? collect() : OrganizationInvitation::query()
            ->withEffectiveStatus(OrganizationInvitation::STATUS_PENDING)
            ->whereIn('license_code_id', $available)
            ->get()->keyBy('license_code_id');

        foreach ($codes as $code) {
            $code->setRelation('license', $code->reference ? $licenses->get($code->reference) : null);
            $code->setRelation('pendingInvitation', $invitations->get($code->id));
        }
    }
}
