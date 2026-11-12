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
        $request->merge($this->normalize($request->only(['name', 'legal_name', 'email', 'phone', 'tax_id', 'address', 'currency'])));
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'locale' => ['sometimes', 'required', 'in:id,en'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'email' => ['sometimes', 'nullable', 'email:rfc,filter', 'max:150'],
            // Local (0…) or international (+country…) number, stored without separators.
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^(0\d{8,13}|\+[1-9]\d{7,14})$/'],
            // NPWP: 15 digits (99.999.999.9-999.999) or the 16-digit NIK-based format, stored as digits.
            'tax_id' => ['sometimes', 'nullable', 'string', 'regex:/^(\d{15}|\d{16})$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'name.min' => 'Nama minimal 2 karakter.',
            'email.email' => 'Format email tidak valid, contoh: admin@perusahaan.co.id.',
            'phone.regex' => 'Nomor telepon diawali 0 atau kode negara (+62) dan berisi 9–15 digit, contoh: 021-5551234 atau +62 812-3456-7890.',
            'tax_id.regex' => 'NPWP harus 15 digit (99.999.999.9-999.999) atau 16 digit.',
            'currency.regex' => 'Mata uang harus kode ISO 4217 tiga huruf, contoh: IDR.',
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

    /**
     * Trims and collapses whitespace so names stay consistent, strips the separators people type
     * in NPWP and phone numbers, and maps blank strings to null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = $key === 'address'
                ? trim(preg_replace("/[ \t]+/", ' ', str_replace("\r\n", "\n", $value)))
                : trim(preg_replace('/\s+/', ' ', $value));
            $value = match ($key) {
                'tax_id' => preg_replace('/[\s.\-]/', '', $value),
                'phone' => preg_replace('/[\s.\-()]/', '', $value),
                'email' => mb_strtolower($value),
                'currency' => strtoupper($value),
                default => $value,
            };
            $input[$key] = $value === '' ? null : $value;
        }

        return $input;
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->tenancy->organizationId());
    }
}
