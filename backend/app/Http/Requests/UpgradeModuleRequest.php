<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpgradeModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in ProjectModuleController.
        return true;
    }

    public function rules(): array
    {
        return [
            // Omitted: upgrade to the latest published version.
            'version' => ['nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'configuration' => ['nullable', 'array'],
        ];
    }
}
