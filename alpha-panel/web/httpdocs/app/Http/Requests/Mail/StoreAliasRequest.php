<?php

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

class StoreAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_local_part' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._%+\-]+$/'],
            'to_address' => ['required', 'email:rfc'],
        ];
    }
}
