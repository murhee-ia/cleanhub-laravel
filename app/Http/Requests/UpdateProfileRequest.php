<?php

namespace App\Http\Requests;

use App\Rules\MaxWords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Only cleaners and employers have an editable profile.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isCleaner() || $user->isEmployer();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->user()->isEmployer()
            ? $this->employerRules()
            : $this->cleanerRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected function cleanerRules(): array
    {
        return [
            'photo' => ['sometimes', 'nullable', File::image()->max(5 * 1024)],
            'bio' => ['sometimes', 'nullable', 'string', new MaxWords(101)],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'languages' => ['sometimes', 'nullable', 'array'],
            'languages.*' => ['string', 'max:255'],
            'cleaning_categories' => ['sometimes', 'nullable', 'array'],
            'cleaning_categories.*' => ['integer', Rule::exists('cleaning_job_categories', 'id')->where('is_active', true)],
            'documents' => ['sometimes', 'nullable', 'array'],
            'documents.*' => ['file', File::types(['pdf'])->max(10 * 1024)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function employerRules(): array
    {
        return [
            'photo' => ['sometimes', 'nullable', File::image()->max(5 * 1024)],
            'employer_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_person_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'about' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'documents' => ['sometimes', 'nullable', 'array'],
            'documents.*' => ['file', File::types(['pdf'])->max(10 * 1024)],
        ];
    }
}
