<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationLicenseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Licenses are bought outside the application; the platform records each purchase here:
 * the Asset Tracker license (renewals included) or a pack of seats for more members.
 */
class LicenseController extends Controller
{
    public function __construct(private readonly Licensing $licensing) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return $this->summary($request, $organization);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        $this->licensing->grant($organization, $data['kind'], (int) ($data['seats'] ?? 1),
            Carbon::parse($data['starts_at'] ?? now()), Carbon::parse($data['expires_at']),
            $data['reference'] ?? null, $data['notes'] ?? null);

        return $this->summary($request, $organization, 201);
    }

    public function revoke(Request $request, Organization $organization, OrganizationLicense $license): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->licensing->revoke($license, $data['reason']);

        return $this->summary($request, $organization);
    }

    /** @return array<string, list<mixed>> */
    private function rules(Request $request): array
    {
        return [
            'kind' => ['required', Rule::in([OrganizationLicense::KIND_BASE, OrganizationLicense::KIND_SEATS])],
            'seats' => ['required_if:kind,'.OrganizationLicense::KIND_SEATS, 'nullable', 'integer', 'min:1', 'max:10000'],
            'starts_at' => ['nullable', 'date', 'before:expires_at'],
            'expires_at' => ['required', 'date', 'after:now'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function summary(Request $request, Organization $organization, int $status = 200): JsonResponse
    {
        $licenses = $organization->licenses()->orderByDesc('expires_at')->orderByDesc('created_at')->get();

        return response()->json(['data' => [
            ...$this->licensing->status($organization->id)->toArray(),
            'licenses' => OrganizationLicenseResource::collection($licenses)->resolve($request),
        ]], $status);
    }
}
