<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * `admin` is intentionally absent: exactly one admin ever exists, so the
     * role can never be granted through this endpoint. The controller also
     * refuses to change the admin's own role.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['cleaner', 'employer', 'moderator'])],
        ];
    }
}
