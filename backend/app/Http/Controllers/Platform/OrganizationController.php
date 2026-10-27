<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Services\OrganizationProvisioner;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationInvitationResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationInvitations $invitations,
        private readonly Licensing $licensing,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Organization::STATUS_ACTIVE, Organization::STATUS_SUSPENDED, Organization::STATUS_ARCHIVED])],
        ]);

        $query = Organization::query()
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('code', 'ilike', ListQuery::like($term))))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        ListQuery::sort($query, $request, ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'], 'name');

        $page = $query->paginate(ListQuery::perPage($request))->withQueryString();
        $page->getCollection()->each(fn (Organization $organization) => $this->withLicense($organization));

        return OrganizationResource::collection($page);
    }

    /**
     * Creates the organization (settings, roles) and invites its first administrator, in one
     * transaction. Nobody becomes a member here: the owner of the address accepts the invitation
     * with a verified account, or signs up through it. The request and the response are the same
     * whether or not the address has an account; the platform never sets anyone's password.
     */
    public function store(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,29}$/', Rule::unique('organizations', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            // Only the address: credentials and profile belong to the account owner.
            'admin' => ['required', 'array:email'],
            'admin.email' => ['required', 'email', 'max:150'],
            // The Asset Tracker license bought with it (1 seat, the administrator's) and any extra seats.
            'license' => ['required', 'array'],
            'license.expires_at' => ['required', 'date', 'after:now'],
            'license.seats' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'license.reference' => ['nullable', 'string', 'max:100'],
            'license.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $adminEmail = $data['admin']['email'];
        $license = $data['license'];
        unset($data['admin'], $data['license']);
        [$organization, $invitation] = DB::transaction(function () use ($provisioner, $data, $adminEmail, $license) {
            $organization = $provisioner->provision(array_filter($data, fn ($v) => $v !== null), null)['organization'];
            $this->grantInitialLicense($organization, $license);

            return [$organization, $this->invitations->inviteFirstAdmin($organization, $adminEmail)];
        });

        return (new OrganizationResource($this->withLicense($organization)))
            ->additional(['meta' => [
                'admin_invitation' => (new OrganizationInvitationResource($invitation))->resolve($request),
                'email_delivery' => $this->emailDelivery(),
            ]])
            ->response()->setStatusCode(201);
    }

    /** Onboarding state of the first administrator: the latest platform invitation, and whether anyone is a member yet. */
    public function adminInvitation(Request $request, Organization $organization): JsonResponse
    {
        $invitation = $this->invitations->firstAdminInvitation($organization);

        return response()->json([
            'data' => [
                'has_members' => $this->invitations->hasMembers($organization),
                'invitation' => $invitation ? (new OrganizationInvitationResource($invitation))->resolve($request) : null,
            ],
            'meta' => ['email_delivery' => $this->emailDelivery()],
        ]);
    }

    /** Invites another address as first administrator (replaces an open invitation); only while the organization has no member. */
    public function inviteAdmin(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);

        return $this->invitationResponse($request, $this->invitations->inviteFirstAdmin($organization, $data['email']), 201);
    }

    public function resendAdminInvitation(Request $request, Organization $organization): JsonResponse
    {
        return $this->invitationResponse($request, $this->invitations->resendFirstAdmin($organization));
    }

    public function revokeAdminInvitation(Request $request, Organization $organization): JsonResponse
    {
        return $this->invitationResponse($request, $this->invitations->revokeFirstAdmin($organization));
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($this->withLicense($organization));
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($organization, $data) {
            $before = $organization->only(array_keys($data));
            $organization->fill($data)->save();
            [$b, $a] = AuditLogger::diff($before, $organization->only(array_keys($data)));
            if ($a !== []) {
                $this->audit->record('organization.updated', $organization, before: $b, after: $a, organizationId: $organization->id);
            }
        });

        return new OrganizationResource($organization);
    }

    public function suspend(Request $request, Organization $organization): OrganizationResource
    {
        return $this->changeStatus($request, $organization, Organization::STATUS_SUSPENDED);
    }

    public function activate(Request $request, Organization $organization): OrganizationResource
    {
        return $this->changeStatus($request, $organization, Organization::STATUS_ACTIVE);
    }

    private function changeStatus(Request $request, Organization $organization, string $status): OrganizationResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($organization->status === Organization::STATUS_ARCHIVED) {
            throw ApiException::conflict('ORGANIZATION_ARCHIVED', 'Organisasi yang diarsipkan tidak dapat diubah statusnya.');
        }

        DB::transaction(function () use ($organization, $status, $data) {
            $before = $organization->status;
            $organization->forceFill(['status' => $status])->save();
            $this->audit->record('organization.status_changed', $organization,
                before: ['status' => $before], after: ['status' => $status],
                metadata: ['reason' => $data['reason']], organizationId: $organization->id);
        });

        return new OrganizationResource($organization);
    }

    /** @param array<string, mixed> $license */
    private function grantInitialLicense(Organization $organization, array $license): void
    {
        $start = Carbon::parse($license['starts_at'] ?? now());
        $end = Carbon::parse($license['expires_at']);
        $reference = $license['reference'] ?? null;
        $notes = $license['notes'] ?? null;
        $this->licensing->grant($organization, OrganizationLicense::KIND_BASE, 1, $start, $end, $reference, $notes);
        if (($license['seats'] ?? 0) > 0) {
            $this->licensing->grant($organization, OrganizationLicense::KIND_SEATS, (int) $license['seats'], $start, $end, $reference, $notes);
        }
    }

    /** Seats and expiry for the console (not a column: computed from the licenses). */
    private function withLicense(Organization $organization): Organization
    {
        return $organization->setAttribute('license_summary', $this->licensing->status($organization->id)->toArray());
    }

    private function invitationResponse(Request $request, OrganizationInvitation $invitation, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => (new OrganizationInvitationResource($invitation))->resolve($request),
            'meta' => ['email_delivery' => $this->emailDelivery()],
        ], $status);
    }

    /** "log": the mailer only writes emails to the server log (development), nothing reaches the invitee. */
    private function emailDelivery(): string
    {
        return in_array(config('mail.default'), ['log', 'array'], true) ? 'log' : 'mail';
    }
}
