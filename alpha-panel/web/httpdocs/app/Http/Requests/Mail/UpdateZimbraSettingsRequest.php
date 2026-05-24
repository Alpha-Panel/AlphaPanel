<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

class UpdateZimbraSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'admin_url' => ['required_if:enabled,true', 'nullable', 'url'],
            'admin_user' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'admin_password' => ['nullable', 'string', 'max:255'],
            'default_mx_host' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'default_mx_priority' => ['nullable', 'integer', 'between:0,65535'],
            'default_spf_include' => ['nullable', 'string', 'max:255'],
            'verify_tls' => ['boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'between:1,120'],
        ];
    }
}
