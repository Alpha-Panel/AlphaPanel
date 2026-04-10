<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoginIpRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_address' => [
                'required',
                'string',
                'max:45',
                'unique:login_ip_rules,ip_address',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        $fail(__('Invalid IP address or CIDR format.'));

                        return;
                    }

                    // Plain IP (IPv4 or IPv6)
                    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                        return;
                    }

                    // CIDR notation
                    if (str_contains($value, '/')) {
                        [$ip, $mask] = explode('/', $value, 2);

                        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ! ctype_digit($mask)) {
                            $fail(__('Invalid IP address or CIDR format.'));

                            return;
                        }

                        $maskInt = (int) $mask;
                        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                        $maxMask = $isIpv4 ? 32 : 128;

                        if ($maskInt < 0 || $maskInt > $maxMask) {
                            $fail(__('Invalid CIDR mask.'));
                        }

                        return;
                    }

                    $fail(__('Invalid IP address or CIDR format.'));
                },
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
