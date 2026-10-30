<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LaVerdadEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = is_string($value) ? substr(strrchr($value, '@') ?: '', 1) : '';
        if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*laverdad\.edu\.ph\z/i', $domain)) {
            $fail('Use a La Verdad email address ending in @laverdad.edu.ph or a school subdomain such as @student.laverdad.edu.ph.');
        }
    }
}
