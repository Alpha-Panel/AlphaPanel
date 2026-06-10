<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validate an RFC-1123 hostname before it is written into Caddyfiles, Apache
 * vhosts and shell scripts. Rejects anything that could break out of a server
 * block or inject a directive (whitespace, braces, control characters, etc.).
 *
 * A leading "*." wildcard label is accepted only when explicitly allowed, for
 * the modes that legitimately use wildcard subdomains.
 */
class ValidHostname implements ValidationRule
{
    public function __construct(private bool $allowWildcard = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The :attribute must be a valid hostname.'));

            return;
        }

        $host = $value;

        if ($this->allowWildcard && str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) {
            $fail(__('The :attribute must be a valid hostname.'));
        }
    }
}
