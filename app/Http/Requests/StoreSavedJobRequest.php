<?php

namespace App\Http\Requests;

use App\Models\SavedJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavedJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SavedJob::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cleaning_job_post_id' => ['required', 'integer', Rule::exists('cleaning_job_posts', 'id')],
        ];
    }
}
