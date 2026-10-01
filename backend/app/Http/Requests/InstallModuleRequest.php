<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstallModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in ProjectModuleController, which has
        // already resolved the project.
        return true;
    }

    public function rules(): array
    {
        return [
            'module' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            // Settings are validated against the module version's
            // config_schema by ModuleService.
            'configuration' => ['nullable', 'array'],
        ];
    }
}
