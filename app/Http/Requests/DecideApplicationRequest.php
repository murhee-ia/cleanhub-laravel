<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('application'));
    }

    /**
     * The message the employer sends the cleaner along with the decision. Fully
     * optional — omitting it leaves decision_message null.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
