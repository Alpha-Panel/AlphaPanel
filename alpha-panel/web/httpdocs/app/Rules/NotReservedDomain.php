<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotReservedDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reserved = config('panel.system_reserved_domains', []);
        $fqdn = strtolower(trim((string) $value));

        if (in_array($fqdn, array_map('strtolower', $reserved), true)) {
            $fail(__('This domain is reserved for a system service and cannot be used.'));
        }
    }
}
