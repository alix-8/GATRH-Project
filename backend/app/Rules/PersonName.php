<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PersonName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! preg_match("/^\\p{L}+(?:[ '’\\-]\\p{L}+)*$/u", $name)) {
            $fail('The :attribute may contain letters, spaces, apostrophes, and hyphens only.');
        }
    }
}
