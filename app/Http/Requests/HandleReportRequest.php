<?php

namespace App\Http\Requests;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared body for the report-handling actions (resolve / reject / escalate /
 * hide / warn): all any of them ever carry is an optional closing note. The
 * route the moderator hit decides the resulting status, not the payload.
 */
class HandleReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('handle', $this->route('report'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
