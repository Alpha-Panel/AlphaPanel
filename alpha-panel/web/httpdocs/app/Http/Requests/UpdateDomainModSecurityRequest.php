<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDomainModSecurityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'modsecurity_enabled' => ['required', 'boolean'],
            'modsecurity_mode' => [
                Rule::excludeIf(fn () => ! $this->boolean('modsecurity_enabled')),
                Rule::requiredIf(fn () => $this->boolean('modsecurity_enabled')),
                'string',
                Rule::in(['active', 'detection_only']),
            ],
            'modsecurity_ip_allowlist' => ['nullable', 'array'],
            'modsecurity_ip_allowlist.*' => ['string', 'max:64'],
            'modsecurity_ip_blocklist' => ['nullable', 'array'],
            'modsecurity_ip_blocklist.*' => ['string', 'max:64'],
            'modsecurity_disabled_rule_ids' => ['nullable', 'array'],
            'modsecurity_disabled_rule_ids.*' => ['integer', 'min:1'],
            'modsecurity_custom_rules' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
