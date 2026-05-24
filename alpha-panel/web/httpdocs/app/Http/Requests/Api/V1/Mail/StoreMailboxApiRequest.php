<?php

namespace App\Http\Requests\Api\V1\Mail;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailboxApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._%+\-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
