<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Caps a string at a maximum word count. Words are counted the same way the
 * frontend's live counter does: trim, split on runs of whitespace, drop empty
 * tokens. The cap is inclusive (exactly `$max` words passes).
 */
class MaxWords implements ValidationRule
{
    public function __construct(private int $max) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $wordCount = count(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY));

        if ($wordCount > $this->max) {
            $fail("The :attribute may not be longer than {$this->max} words.");
        }
    }
}
