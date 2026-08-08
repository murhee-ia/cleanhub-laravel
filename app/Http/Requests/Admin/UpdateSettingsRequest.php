<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the known setting keys can be written, each a positive integer. Any key
 * not in Setting::DEFAULTS is silently absent from the rules, so it fails the
 * `array` key check and can never reach the store.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $rules[$key] = ['sometimes', 'integer', 'min:1'];
        }

        return $rules;
    }
}
