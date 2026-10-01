<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in ProjectController.
        return true;
    }

    public function rules(): array
    {
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:255'],
            // Omitted: generated from the name. Unique within the organization.
            'slug' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->where('organization_id', $organization->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'template_id' => ['nullable', 'integer', Rule::exists('templates', 'id')->where('status', 'available')],
            'settings' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
