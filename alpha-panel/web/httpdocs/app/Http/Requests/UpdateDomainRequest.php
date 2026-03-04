<?php

namespace App\Http\Requests;

use App\Enums\DomainType;
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
            'php_version_id' => [
                'nullable',
                'exists:php_versions,id',
                Rule::requiredIf(fn () => $this->input('type') === 'apache_reverse_proxy'),
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
