<?php

namespace App\Http\Requests\Api\V1\Mail;

use Illuminate\Foundation\Http\FormRequest;

class SetPasswordApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
