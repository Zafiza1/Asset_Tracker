<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in ProjectController.
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');

        // template_id is not editable here: moving a project to another
        // template is a Template Engine operation (Phase 7).
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')
                    ->where('organization_id', $project->organization_id)
                    ->ignore($project->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'archived'])],
            'settings' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
