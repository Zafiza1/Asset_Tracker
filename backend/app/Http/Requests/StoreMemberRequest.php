<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adds an existing, registered user to an organization or project.
 */
class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the member controllers.
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            // Role slug; which roles are allowed at this level, and up to which
            // level the caller may grant, is checked by RoleAssignment.
            'role' => ['nullable', 'string', 'max:100'],
        ];
    }
}
