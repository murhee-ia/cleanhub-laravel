<?php

namespace App\Http\Requests\Admin;

use App\Models\CleaningJobCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * `slug` is optional — the controller derives it from the name when
     * omitted. New categories default to active.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(CleaningJobCategory::class, 'name')],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique(CleaningJobCategory::class, 'slug')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
