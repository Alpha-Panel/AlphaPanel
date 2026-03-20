<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFirewallRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'chain' => ['required', Rule::in(['INPUT', 'OUTPUT'])],
            'action' => ['required', Rule::in(['ACCEPT', 'DROP', 'REJECT'])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp', 'all'])],
            'source' => ['nullable', 'string', 'max:50'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'comment' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
