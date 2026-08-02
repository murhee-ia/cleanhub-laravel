<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('application'));
    }

    /**
     * The note must be present on every request but may be null, which is how
     * the employer clears a note they saved earlier.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
