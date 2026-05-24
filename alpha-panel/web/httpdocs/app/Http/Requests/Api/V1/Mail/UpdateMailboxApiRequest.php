<?php

namespace App\Http\Requests\Api\V1\Mail;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailboxApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:120'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
