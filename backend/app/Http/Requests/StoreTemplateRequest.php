<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Templates are control plane resources - authorization handled by policy
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:templates,slug',
            'description' => 'nullable|string',
            'category' => 'nullable|in:general,industrial,warehouse,vehicle,equipment',
            'version' => 'nullable|string|max:50',
            'status' => 'nullable|in:available,deprecated',
            'default_modules' => 'nullable|array',
            'default_modules.*' => 'string|exists:modules,slug',
            'default_settings' => 'nullable|array',
            'metadata' => 'nullable|array',
            'version_description' => 'nullable|string',
            'released_at' => 'nullable|date',
            'modules' => 'nullable|array',
            'modules.*.module_slug' => 'required|string|exists:modules,slug',
            'modules.*.version_constraint' => 'nullable|string',
            'modules.*.required' => 'nullable|boolean',
            'modules.*.default_config' => 'nullable|array',
            'modules.*.sort_order' => 'nullable|integer|min:0',
        ];
    }
}
