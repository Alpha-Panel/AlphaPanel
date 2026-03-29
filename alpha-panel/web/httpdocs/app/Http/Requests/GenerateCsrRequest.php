<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateCsrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'common_name' => ['required', 'string', 'max:255'],
            'san_domains' => ['nullable', 'array'],
            'san_domains.*' => ['string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'organizational_unit' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:255'],
            'locality' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'key_type' => ['required', 'string', 'in:rsa2048,rsa4096,ecdsa256,ecdsa384'],
        ];
    }
}
