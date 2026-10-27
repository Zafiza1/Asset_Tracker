<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\OrganizationSettingResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationProfileController extends Controller
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): OrganizationResource
    {
        return new OrganizationResource($this->organization());
    }

    public function update(Request $request): OrganizationResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'locale' => ['sometimes', 'required', 'in:id,en'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $organization = $this->organization();

        DB::transaction(function () use ($organization, $data) {
            $before = $organization->only(array_keys($data));
            $organization->fill($data)->save();
            [$b, $a] = AuditLogger::diff($before, $organization->only(array_keys($data)));
            if ($a !== []) {
                $this->audit->record('organization.updated', $organization, before: $b, after: $a);
            }
        });

        return new OrganizationResource($organization);
    }

    public function settings(): OrganizationSettingResource
    {
        return new OrganizationSettingResource(OrganizationSetting::query()->firstOrFail());
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->tenancy->organizationId());
    }
}
