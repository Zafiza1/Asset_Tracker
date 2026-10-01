<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Templates are control plane resources - authorization handled by policy
        return true;
    }

    public function rules(): array
    {
        $template = $this->route('template');

        return [
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('templates')->ignore($template->id)],
            'description' => 'nullable|string',
            'category' => 'nullable|in:general,industrial,warehouse,vehicle,equipment',
            'version' => 'nullable|string|max:50',
            'status' => 'nullable|in:available,deprecated',
            'default_modules' => 'nullable|array',
            'default_modules.*' => 'string|exists:modules,slug',
            'default_settings' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }
}
