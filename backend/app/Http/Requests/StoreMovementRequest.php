<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (policy + project membership) is handled explicitly
        // in MovementController, which has already resolved the asset.
        return true;
    }

    public function rules(): array
    {
        $projectId = app(TenantContext::class)->projectId();

        $locationExistsInProject = Rule::exists('locations', 'id')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at');

        return [
            'to_location_id' => ['required', 'integer', $locationExistsInProject],
            'from_location_id' => ['nullable', 'integer', $locationExistsInProject],
            'source' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
