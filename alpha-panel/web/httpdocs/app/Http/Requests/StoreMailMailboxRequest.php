<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'local_part.regex' => __('Local part may only contain letters, numbers, dots, hyphens, and underscores.'),
            'local_part.required' => __('The mailbox username is required.'),
            'password.min' => __('Password must be at least 8 characters.'),
        ];
    }
}
