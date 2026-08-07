<?php

namespace App\Http\Requests;

use App\Models\Rating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Rating::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'application_id' => ['required', 'integer', Rule::exists('applications', 'id')],
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'text' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
