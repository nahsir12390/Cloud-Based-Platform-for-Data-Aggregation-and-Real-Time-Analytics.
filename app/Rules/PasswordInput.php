<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt only uses the first 72 bytes. Never accept a silently truncated password.
        if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('The :attribute is too long or contains unsupported characters. Please choose a different password.');
        }
    }
}
