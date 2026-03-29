<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'private_key' => ['required', 'string'],
            'certificate' => ['required', 'string'],
            'ca_bundle' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
