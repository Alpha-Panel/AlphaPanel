<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDomainIpRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => $this->input('path') ?: '',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_address' => [
                'required',
                'string',
                'max:45',
                'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
                Rule::unique('domain_ip_rules')
                    ->where('domain_id', $this->route('domain')->id)
                    ->where('path', $this->input('path', '')),
            ],
            'path' => [
                'present',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== '' && ! preg_match('/^\/[a-zA-Z0-9\/_\-.*]+$/', $value)) {
                        $fail(__('Path must start with / and may contain letters, numbers, slashes, dashes, underscores, dots, and wildcards.'));
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ip_address.regex' => __('Please enter a valid IPv4 address or CIDR notation (e.g. 192.168.1.0/24).'),
            'ip_address.unique' => __('This IP address is already configured for this path on this domain.'),
        ];
    }
}
