<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoginIpRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_address' => [
                'required',
                'string',
                'max:45',
                'unique:login_ip_rules,ip_address',
                'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/',
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
