<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the member controllers.
        return true;
    }

    public function rules(): array
    {
        return [
            // null: plain member without a role at this level.
            'role' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }
}
