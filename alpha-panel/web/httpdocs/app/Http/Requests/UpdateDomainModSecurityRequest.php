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
        ];
    }
}
