<?php

namespace App\Http\Requests;

use App\Enums\DomainType;
use App\Enums\SslMethod;
use App\Rules\NotReservedDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_user_id' => ['sometimes', 'exists:users,id'],
            'fqdn' => [
                'required',
                'string',
                'max:255',
                Rule::unique('domains', 'fqdn')->ignore($this->route('domain')),
                new NotReservedDomain,
            ],
            'type' => ['required', new Enum(DomainType::class)],
            'root_path' => ['nullable', 'string', 'max:500'],
            'enable_www_redirect' => ['boolean'],
            'additional_hostnames' => ['nullable', 'array'],
            'additional_hostnames.*' => ['string', 'max:255'],
            'enable_worker' => ['boolean'],
            'worker_num' => [
                Rule::excludeIf(fn () => ! $this->boolean('enable_worker')),
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
            'worker_watch' => [
                Rule::excludeIf(fn () => ! $this->boolean('enable_worker')),
                'boolean',
            ],
            'worker_max_requests' => [
                Rule::excludeIf(fn () => ! $this->boolean('enable_worker')),
                'nullable',
                'integer',
                'min:50',
                'max:10000',
            ],
            'forwarded_port' => [
                Rule::excludeIf(fn () => $this->input('type') !== 'apache_reverse_proxy'),
                'nullable',
                'integer',
                'in:80,443',
            ],
            'php_version_id' => [
                'nullable',
                'exists:php_versions,id',
                Rule::requiredIf(fn () => $this->input('type') === 'apache_reverse_proxy'),
            ],
            'linked_domain_id' => ['nullable', 'exists:domains,id'],
            'ssl_method' => ['sometimes', new Enum(SslMethod::class)],
            'cors_enabled' => ['boolean'],
            'cors_allowed_origins' => [
                'nullable',
                'string',
                'max:2000',
                Rule::excludeIf(fn () => ! $this->boolean('cors_enabled')),
            ],
            'bypass_reverse_proxy' => ['boolean'],
            'custom_caddy_directives' => [
                'nullable',
                'string',
                'max:5000',
                Rule::requiredIf(fn () => $this->boolean('bypass_reverse_proxy')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $blocked = ['import', '{env.', '{system.', 'exec', '{http.vars.'];
                    $lower = strtolower((string) $value);
                    foreach ($blocked as $pattern) {
                        if (str_contains($lower, $pattern)) {
                            $fail(__('Custom Caddy directives contain a blocked pattern: :pattern', ['pattern' => $pattern]));

                            return;
                        }
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'php_version_id.required_if' => 'PHP version is required for Apache + Reverse Proxy domains.',
        ];
    }
}
