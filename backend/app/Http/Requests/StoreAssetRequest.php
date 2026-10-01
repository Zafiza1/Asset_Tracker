<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (policy + project membership) is handled explicitly
        // in AssetController, which has already resolved the project.
        return true;
    }

    public function rules(): array
    {
        $projectId = app(TenantContext::class)->projectId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where('project_id', $projectId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'asset_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
