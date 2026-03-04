<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCloudflareSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'setting' => [
                'required',
                'string',
                Rule::in([
                    'security_level',
                    'ssl',
                    'always_use_https',
                    'automatic_https_rewrites',
                    'min_tls_version',
                    'tls_1_3',
                    'browser_cache_ttl',
                    'development_mode',
                    'websockets',
                    'ip_geolocation',
                    'opportunistic_onion',
                    'http3',
                    'early_hints',
                    'security_header',
                    'under_attack',
                ]),
            ],
            'value' => ['present'],
            'value.enabled' => ['nullable', 'boolean'],
            'value.max_age' => ['nullable', 'integer', 'min:0', 'max:31536000'],
            'value.include_subdomains' => ['nullable', 'boolean'],
            'value.preload' => ['nullable', 'boolean'],
            'value.nosniff' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $setting = (string) $this->input('setting');
            $value = $this->input('value');

            if ($setting === 'under_attack') {
                if (! is_bool($value)) {
                    $validator->errors()->add('value', __('Value must be true or false for under attack mode.'));
                }

                return;
            }

            if ($setting === 'security_header') {
                if (! is_array($value) || ! Arr::has($value, 'enabled')) {
                    $validator->errors()->add('value', __('HSTS settings payload is invalid.'));
                }

                return;
            }

            if ($setting === 'security_level') {
                if (! in_array($value, ['essentially_off', 'low', 'medium', 'high', 'under_attack'], true)) {
                    $validator->errors()->add('value', __('Invalid security level value.'));
                }

                return;
            }

            if ($setting === 'ssl') {
                if (! in_array($value, ['off', 'flexible', 'full', 'strict'], true)) {
                    $validator->errors()->add('value', __('Invalid SSL mode value.'));
                }

                return;
            }

            if ($setting === 'min_tls_version') {
                if (! in_array($value, ['1.0', '1.1', '1.2', '1.3'], true)) {
                    $validator->errors()->add('value', __('Invalid minimum TLS version value.'));
                }

                return;
            }

            if ($setting === 'browser_cache_ttl') {
                if (! is_numeric($value)) {
                    $validator->errors()->add('value', __('Browser cache TTL must be numeric.'));
                }

                return;
            }

            if (! in_array($value, ['on', 'off'], true)) {
                $validator->errors()->add('value', __('Setting value must be on or off.'));
            }
        });
    }
}
