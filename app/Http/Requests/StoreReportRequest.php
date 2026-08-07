<?php

namespace App\Http\Requests;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Report::class);
    }

    /**
     * `reportable_type` accepts only the three short morph aliases the app
     * exposes. The row's actual existence is checked in the controller, which
     * resolves the alias to a model and 404s a missing target — that keeps the
     * type→table mapping in one place instead of duplicating it as an exists
     * rule per type here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reportable_type' => ['required', Rule::in(['user', 'job_post', 'rating'])],
            'reportable_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
