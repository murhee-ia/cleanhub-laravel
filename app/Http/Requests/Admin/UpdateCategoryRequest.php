<?php

namespace App\Http\Requests\Admin;

use App\Models\CleaningJobCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * Every field is optional on update; uniqueness ignores the category being
     * edited so re-saving its own name/slug isn't a conflict. Toggling
     * `is_active` to false is how a category is retired without a hard delete
     * that the job-post foreign key would forbid anyway.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique(CleaningJobCategory::class, 'name')->ignore($category)],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique(CleaningJobCategory::class, 'slug')->ignore($category)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
