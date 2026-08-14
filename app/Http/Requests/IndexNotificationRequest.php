<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unread_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:50', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('unread_only')) {
            return;
        }

        $unreadOnly = match ($this->input('unread_only')) {
            'true' => true,
            'false' => false,
            default => null,
        };

        if ($unreadOnly !== null) {
            $this->merge(['unread_only' => $unreadOnly]);
        }
    }
}
