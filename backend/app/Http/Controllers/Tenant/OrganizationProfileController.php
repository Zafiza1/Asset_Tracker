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

    public function updateSettings(Request $request): OrganizationSettingResource
    {
        $data = $request->validate([
            'asset_number_format' => ['sometimes', 'required', 'string', 'max:60', 'regex:/\{SEQ(:\d)?\}/'],
            'transaction_number_format' => ['sometimes', 'required', 'string', 'max:60', 'regex:/\{SEQ(:\d)?\}/'],
            'allow_self_approval_default' => ['sometimes', 'required', 'boolean'],
            'max_upload_mb' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
        ]);

        $settings = OrganizationSetting::query()->firstOrFail();

        DB::transaction(function () use ($settings, $data) {
            $before = $settings->only(array_keys($data));
            $settings->fill($data)->save();
            [$b, $a] = AuditLogger::diff($before, $settings->only(array_keys($data)));
            if ($a !== []) {
                $this->audit->record('settings.updated', 'OrganizationSetting', $settings->organization_id, before: $b, after: $a);
            }
        });

        return new OrganizationSettingResource($settings);
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->tenancy->organizationId());
    }
}
