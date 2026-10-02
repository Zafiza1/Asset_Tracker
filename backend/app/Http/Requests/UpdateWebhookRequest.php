<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'endpoint' => ['sometimes', 'url', 'max:2048'],
            'secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'active' => ['sometimes', 'boolean'],
            'retry_policy' => ['nullable', 'array'],
            'retry_policy.max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'retry_policy.retry_delay' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'retry_policy.backoff_multiplier' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
