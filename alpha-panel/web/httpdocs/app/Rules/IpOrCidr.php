<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validate that a value is a single IP address (v4 or v6) or a CIDR range.
 *
 * WAF IP rules are interpolated into ModSecurity `@ipMatch` operands, so an
 * unvalidated value could break out of the quoted operand or inject additional
 * directives. We accept only strict IP/CIDR notation with a prefix length that
 * is valid for the detected address family.
 */
class IpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('The :attribute must be a valid IP address or CIDR range.'));
        }
    }

    /**
     * Determine whether the given value is a valid IP address or CIDR range.
     */
    public static function isValid(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, "\0")) {
            return false;
        }

        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        $parts = explode('/', $value);
        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $prefix] = $parts;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isValidPrefix($prefix, 32);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isValidPrefix($prefix, 128);
        }

        return false;
    }

    /**
     * Validate a CIDR prefix length for the given maximum (32 for IPv4, 128 for IPv6).
     */
    private static function isValidPrefix(string $prefix, int $max): bool
    {
        if ($prefix === '' || preg_match('/^\d{1,3}$/', $prefix) !== 1) {
            return false;
        }

        $value = (int) $prefix;

        return $value >= 0 && $value <= $max && (string) $value === $prefix;
    }
}
