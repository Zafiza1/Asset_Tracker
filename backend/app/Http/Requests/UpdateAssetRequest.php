<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (policy + project membership) is handled explicitly
        // in AssetController, which has already resolved the asset.
        return true;
    }

    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'serial_number' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->where('project_id', $asset->project_id)
                    ->ignore($asset->id)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'asset_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
