<?php

namespace App\Http\Requests;

use App\Models\Application;
use App\Rules\MaxWords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cleaning_job_post_id' => ['required', 'integer', Rule::exists('cleaning_job_posts', 'id')],
            'message' => ['sometimes', 'nullable', 'string', new MaxWords(101)],
            'resume' => ['sometimes', 'nullable', 'file', File::types(['pdf'])->max(10 * 1024)],
        ];
    }
}
