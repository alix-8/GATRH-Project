<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StudentName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = array_map(
            fn (string $part) => preg_replace('/\s+/u', ' ', trim($part)),
            explode(',', (string) $value)
        );

        if (count($parts) !== 2 || in_array('', $parts, true)) {
            $fail('The :attribute must use the format "Last Name, First Name".');

            return;
        }

        foreach ($parts as $part) {
            if (! preg_match("/^\\p{L}+(?:[ '’\\-]\\p{L}+)*$/u", $part)) {
                $fail('The :attribute may contain letters, spaces, apostrophes, and hyphens only.');

                return;
            }
        }
    }
}
