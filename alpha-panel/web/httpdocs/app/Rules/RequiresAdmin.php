<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RequiresAdmin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! auth()->user()?->isAdmin()) {
            $fail(__('Only administrators may create the wildcard catch-all domain.'));
        }
    }
}
