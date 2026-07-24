<?php

namespace App\Http\Requests;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Models\CleaningJobPost;
use App\Rules\MaxWords;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateCleaningJobPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('cleaningJobPost');

        return $post instanceof CleaningJobPost && $this->user()->can('update', $post);
    }

    /**
     * A draft post's content is fully editable but its status is fixed at
     * `open` until it is published. Once published, the content is locked and
     * the only editable field is `status`, which moves forward only
     * (open → reviewing → closed → completed) and can never be set to
     * `removed` (a moderator/admin hide). A completed post is terminal.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $post = $this->route('cleaningJobPost');

        if ($post instanceof CleaningJobPost && $post->visibility === JobPostVisibility::Published) {
            return $this->publishedRules($post);
        }

        return $this->draftRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected function draftRules(): array
    {
        return [
            'title' => ['sometimes', 'string', new MaxWords(101)],
            'cleaning_job_category_id' => ['sometimes', 'integer', Rule::exists('cleaning_job_categories', 'id')->where('is_active', true)],
            'description' => ['sometimes', 'string'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'qualifications' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'country' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'schedule_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'cleaners_needed' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'application_deadline' => ['sometimes', 'nullable', 'date', 'before_or_equal:schedule_date'],
            'visibility' => ['sometimes', Rule::enum(JobPostVisibility::class)],
            'status' => ['prohibited'],
            'pay_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'pay_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'media' => ['sometimes', 'nullable', 'array', 'max:10'],
            'media.*' => ['image', File::image()->max(5 * 1024)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function publishedRules(CleaningJobPost $post): array
    {
        $locked = ['prohibited'];

        return [
            'status' => ['sometimes', Rule::enum(JobPostStatus::class)->except(JobPostStatus::Removed), $this->forwardOnlyStatus($post)],
            'title' => $locked,
            'cleaning_job_category_id' => $locked,
            'description' => $locked,
            'requirements' => $locked,
            'qualifications' => $locked,
            'country' => $locked,
            'city' => $locked,
            'address' => $locked,
            'schedule_date' => $locked,
            'start_time' => $locked,
            'end_time' => $locked,
            'cleaners_needed' => $locked,
            'application_deadline' => $locked,
            'visibility' => $locked,
            'pay_amount' => $locked,
            'pay_currency' => $locked,
            'media' => $locked,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prohibited' => 'A published job post is locked; only its status can be changed.',
            'status.prohibited' => "A job post's status cannot be changed until it is published.",
        ];
    }

    /**
     * Enforce the forward-only status flow: a post may only advance to a later
     * step (open → reviewing → closed → completed), never move backward, and a
     * completed post is terminal.
     */
    protected function forwardOnlyStatus(CleaningJobPost $post): Closure
    {
        $order = [
            JobPostStatus::Open->value => 0,
            JobPostStatus::Reviewing->value => 1,
            JobPostStatus::Closed->value => 2,
            JobPostStatus::Completed->value => 3,
        ];

        return function (string $attribute, mixed $value, Closure $fail) use ($order, $post): void {
            $current = $post->status->value;

            if (! array_key_exists($current, $order)) {
                $fail("This job post's status can no longer be changed.");

                return;
            }

            if (is_string($value) && array_key_exists($value, $order) && $order[$value] < $order[$current]) {
                $fail('A job post status can only move forward: open → reviewing → closed → completed.');
            }
        };
    }
}
