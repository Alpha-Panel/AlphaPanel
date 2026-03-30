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
            'private_key' => ['required', 'string', 'regex:/-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/'],
            'certificate' => ['required', 'string', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'ca_bundle' => ['nullable', 'string', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'private_key.regex' => __('Invalid private key format. Must be PEM format.'),
            'certificate.regex' => __('Invalid certificate format. Must be PEM format.'),
            'ca_bundle.regex' => __('Invalid CA bundle format. Must be PEM format.'),
        ];
    }
}
