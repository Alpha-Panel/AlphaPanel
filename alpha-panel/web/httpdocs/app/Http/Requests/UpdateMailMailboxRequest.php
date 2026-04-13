<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:255'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
