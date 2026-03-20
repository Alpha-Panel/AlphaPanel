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
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/'],
            'ports' => ['nullable', 'array'],
            'ports.*' => ['integer', 'min:1', 'max:65535'],
            'comment' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
