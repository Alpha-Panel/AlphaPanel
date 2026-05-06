<?php

namespace App\Rules;

use App\Models\Domain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoExistingCatchall implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Domain::where('fqdn', '*')->exists()) {
            $fail(__('Wildcard catch-all already defined on this server'));
        }
    }
}
