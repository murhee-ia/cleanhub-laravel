<?php

namespace App\Http\Requests;

use App\Enums\JobPostVisibility;
use App\Models\CleaningJobPost;
use App\Rules\MaxWords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreCleaningJobPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CleaningJobPost::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', new MaxWords(101)],
            'cleaning_job_category_id' => ['required', 'integer', Rule::exists('cleaning_job_categories', 'id')->where('is_active', true)],
            'description' => ['required', 'string'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'qualifications' => ['nullable', 'string', 'max:5000'],
            'country' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'schedule_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'cleaners_needed' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'application_deadline' => ['nullable', 'date', 'before_or_equal:schedule_date'],
            'visibility' => ['sometimes', Rule::enum(JobPostVisibility::class)],
            'pay_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'pay_currency' => ['nullable', 'string', 'size:3'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => ['image', File::image()->max(5 * 1024)],
        ];
    }
}
