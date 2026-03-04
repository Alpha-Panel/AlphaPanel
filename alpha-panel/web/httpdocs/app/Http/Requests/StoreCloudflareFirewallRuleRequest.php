<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCloudflareFirewallRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expression' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'string', Rule::in(['block', 'allow', 'challenge', 'js_challenge', 'log'])],
            'description' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
