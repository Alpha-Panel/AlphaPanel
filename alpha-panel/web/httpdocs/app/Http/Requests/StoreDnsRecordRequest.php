<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDnsRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'record_type' => ['required', 'string', Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'HTTPS', 'NS'])],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:4096'],
            'ttl' => ['nullable', 'integer', 'min:1'],
            'proxied' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'dns_id' => ['nullable', 'string'],
            'service' => ['nullable', 'string'],
            'protocol' => ['nullable', 'string'],
            'weight' => ['nullable', 'integer'],
            'port' => ['nullable', 'integer'],
            'target' => ['nullable', 'string'],
            'flags' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string'],
        ];
    }
}
