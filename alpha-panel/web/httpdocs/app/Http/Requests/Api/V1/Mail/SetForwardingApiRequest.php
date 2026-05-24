<?php

namespace App\Http\Requests\Api\V1\Mail;

use Illuminate\Foundation\Http\FormRequest;

class SetForwardingApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'addresses' => ['array'],
            'addresses.*' => ['email:rfc'],
            'keep_local' => ['boolean'],
        ];
    }
}
