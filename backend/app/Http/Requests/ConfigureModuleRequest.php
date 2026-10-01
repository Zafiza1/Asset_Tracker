<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in ProjectModuleController.
        return true;
    }

    public function rules(): array
    {
        return [
            // Present but possibly empty; each setting is validated against
            // the installed version's config_schema by ModuleService.
            'configuration' => ['present', 'array'],
        ];
    }
}
